<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventRequest;
use App\Models\Event;
use App\Services\EventService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function __construct(
        private readonly EventService $events,
        private readonly SettingsService $settings
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(Event::query()->orderBy('start_at', 'desc')->get());
    }

    public function store(EventRequest $request): JsonResponse
    {
        $timezone = $this->settings->get('event_timezone', 'Europe/Berlin');
        $data = $request->validated();
        if (!empty($data['start_at'])) {
            $data['start_at'] = Carbon::createFromFormat('Y-m-d\TH:i', substr($data['start_at'], 0, 16), $timezone)->setTimezone('UTC');
        }
        if (!empty($data['end_at'])) {
            $data['end_at'] = Carbon::createFromFormat('Y-m-d\TH:i', substr($data['end_at'], 0, 16), $timezone)->setTimezone('UTC');
        }
        $event = Event::create($data);
        return response()->json($event, 201);
    }

    public function update(EventRequest $request, Event $event): JsonResponse
    {
        $timezone = $this->settings->get('event_timezone', 'Europe/Berlin');
        $data = $request->validated();
        if (!empty($data['start_at'])) {
            $data['start_at'] = Carbon::createFromFormat('Y-m-d\TH:i', substr($data['start_at'], 0, 16), $timezone)->setTimezone('UTC');
        }
        if (!empty($data['end_at'])) {
            $data['end_at'] = Carbon::createFromFormat('Y-m-d\TH:i', substr($data['end_at'], 0, 16), $timezone)->setTimezone('UTC');
        }
        $event->update($data);
        return response()->json($event);
    }

    public function destroy(Event $event): JsonResponse
    {
        $event->delete();
        return response()->json(['message' => __('event_deleted')]);
    }

    public function upcoming(): JsonResponse
    {
        $list = $this->events->upcoming(10)->map(fn ($e) => [
            'id' => $e->id,
            'title' => $e->title,
            'start_at' => $e->start_at,
            'end_at' => $e->end_at,
            'location' => $e->location,
            'active' => $e->active,
            'display' => $this->events->format($e),
        ]);

        $next = $this->events->next();

        return response()->json([
            'next' => $next ? $this->events->format($next) : null,
            'upcoming' => $list,
        ]);
    }
}
