<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\Activity;
use Modules\CORE\Models\User;
use Modules\CORE\Services\ActivityService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityService $activities,
    ) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest(
            $request,
            allowedSorts: ['id', 'created_at', 'event', 'log_name'],
            allowedFilters: ['log_name', 'causer', 'subject_type', 'date_from', 'date_to'],
        );

        return Inertia::render('CORE::Activities/Index', [
            'activities' => PaginatedResource::from(
                $this->activities->paginate($dt)->through(fn (Activity $activity): array => $activity->toTableRow())
            ),
            'filterDefs' => [
                [
                    'key' => 'log_name',
                    'label' => 'Log',
                    'kind' => 'select',
                    'options' => $this->activities->logNames()
                        ->map(fn (string $name): array => ['value' => $name, 'label' => ucfirst($name)])
                        ->values()
                        ->all(),
                ],
                [
                    'key' => 'subject_type',
                    'label' => 'Subject',
                    'kind' => 'select',
                    'options' => array_map(
                        fn (string $type): array => ['value' => $type, 'label' => ucfirst($type).'s'],
                        $this->activities->subjectTypeOptions()
                    ),
                ],
                [
                    'key' => 'causer',
                    'label' => 'Performed by',
                    'kind' => 'endpoint',
                    'endpoint' => route('core.api.options', 'causers'),
                    'placeholder' => 'Search people…',
                ],
                [
                    'key' => 'date_from',
                    'label' => 'From',
                    'kind' => 'date',
                ],
                [
                    'key' => 'date_to',
                    'label' => 'To',
                    'kind' => 'date',
                ],
            ],
        ]);
    }

    public function show(Activity $activity): Response
    {
        $activity->loadMissing(['causer', 'subject']);

        return Inertia::render('CORE::Activities/Show', [
            'activity' => [
                'id' => $activity->id,
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'event' => $activity->event,
                'created_at' => $activity->created_at?->toIso8601String(),
                'ip' => $activity->properties?->get('ip'),
                'user_agent' => $activity->properties?->get('user_agent'),
                'causer' => $activity->causer instanceof User
                    ? [
                        'id' => $activity->causer->id,
                        'name' => $activity->causer->name,
                        'email' => $activity->causer->email,
                    ]
                    : null,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'subject' => $activity->subject instanceof Model
                    ? [
                        'type' => class_basename((string) $activity->subject_type),
                        'id' => $activity->subject->getKey(),
                        'label' => (string) ($activity->subject->name ?? $activity->subject->getKey()),
                    ]
                    : null,
                'changes' => [
                    'old' => $activity->properties?->get('old') ?? [],
                    'new' => $activity->properties?->get('attributes') ?? [],
                ],
                'properties' => $activity->properties?->except(['old', 'attributes'])->toArray() ?? [],
            ],
        ]);
    }
}
