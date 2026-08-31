<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app notification bell (P21): the same rows, filter and mutation
 * semantics as Filament's own DatabaseNotifications Livewire component,
 * served headlessly.
 *
 * There is no resource-level gate here because there is no resource — the
 * feed is the authenticated user's own rows, so the auth middleware's 401 is
 * the only gate. The same reasoning DashboardController documents.
 */
final class NotificationsController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->query($request);

        // A host whose user model never gained Notifiable gets an empty
        // page, never a 500: the routes exist unconditionally (conditional
        // route registration is a route-cache trap), so this guard is where
        // the misconfiguration degrades. Doctor names the fix.
        if ($query === null) {
            return ConditionalJson::respond($request, [
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) config('filament-mobile.per_page'),
                    'total' => 0,
                ],
                'unread' => 0,
            ]);
        }

        // No orderBy: Notifiable's notifications() relation is already
        // latest()-ordered, which is exactly the order the web bell renders.
        $page = $query->paginate(config('filament-mobile.per_page'));

        return ConditionalJson::respond($request, [
            'data' => array_map($this->row(...), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            // Top-level, beside the envelope: the one number the badge needs,
            // so page 1 is the whole bell state in a single ETag'd GET.
            'unread' => $this->unread($request),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->markAsRead();

        return response()->json(['unread' => $this->unread($request)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        // unread() scope + update, the web component's own
        // markAllNotificationsAsRead() verbatim.
        $this->query($request)?->unread()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
    }

    public function clear(Request $request): JsonResponse
    {
        // Read AND unread rows, mirroring clearNotifications() verbatim.
        $this->query($request)?->delete();

        return response()->json(null, 204);
    }

    /**
     * The web bell's exact query: the user's own rows, filtered to the
     * payloads Filament itself wrote (`data->format = 'filament'`). Null —
     * not a throw — when the user model is not Notifiable; every action
     * above degrades to its empty answer.
     */
    private function query(Request $request): ?MorphMany
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'notifications')) {
            return null;
        }

        return $user->notifications()->where('data->format', 'filament');
    }

    private function unread(Request $request): int
    {
        return (int) ($this->query($request)?->unread()->count() ?? 0);
    }

    /**
     * One answer for a foreign, missing or non-uuid id: the scoped query
     * finds nothing, so a cross-user id is indistinguishable from one that
     * never existed.
     */
    private function find(Request $request, string $id): DatabaseNotification
    {
        return $this->query($request)?->whereKey($id)->first()
            ?? abort(404, "No notification [{$id}].");
    }

    /**
     * The published row: id, title, body, status, color, date, readAt,
     * actions. `icon`/`iconColor` name a heroicon no client of this contract
     * can honour, and `view`/`viewData` have no data contract — neither is
     * ever published; a view-only notification still publishes its stored
     * title/body. Every field fails closed to null rather than trusting the
     * stored payload's shape.
     *
     * @return array<string, mixed>
     */
    private function row(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        return [
            'id' => (string) $notification->getKey(),
            'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
            'body' => is_string($data['body'] ?? null) ? $data['body'] : null,
            'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
            'color' => is_string($data['color'] ?? null) ? $data['color'] : null,
            'date' => $notification->created_at?->clone()->utc()->toIso8601ZuluString(),
            'readAt' => $notification->read_at?->clone()->utc()->toIso8601ZuluString(),
            'actions' => $this->actions($data['actions'] ?? null),
        ];
    }

    /**
     * Only entries with a non-empty url survive, label + url only: a
     * Livewire event-dispatching action cannot run headlessly, and an
     * ActionGroup entry carries no url of its own. Per-entry fail-closed —
     * one malformed action never costs the row.
     *
     * @return list<array{label: string, url: string}>
     */
    private function actions(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $actions = [];

        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $url = $entry['url'] ?? null;
            $label = $entry['label'] ?? null;

            if (is_string($url) && $url !== '' && is_string($label)) {
                $actions[] = ['label' => $label, 'url' => $url];
            }
        }

        return $actions;
    }
}
