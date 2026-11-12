<?php

namespace App\Support;

use App\Contracts\Authorization as AccessService;
use App\Modules\AccessControl\ActionAuthorizer;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\HousingMedia;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\RentalPolicy;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\Request;

abstract class ProductPresenter
{
    protected Request $request;

    protected $user;

    protected ?Workspace $workspace;

    protected array $vm;

    public function __construct(protected ScopedRepository $scope, protected AccessService $access) {}

    public static function money($value): string
    {
        return number_format((float) $value, 0, ',', '.').' ₫';
    }

    public static function label(?string $status): string
    {
        return config('labels.'.$status, 'Chưa xác định');
    }

    protected function field(string $name, string $label, string $type = 'text', array $options = [], mixed $value = null, bool $required = true): array
    {
        return compact('name', 'label', 'type', 'options', 'value', 'required');
    }

    protected function galleryField(string $label, $model = null): array
    {
        $options = $model ? app(HousingMedia::class)->rows($model)->mapWithKeys(fn ($m) => [$m->id => route('housing.image', ['media' => $m->id])])->all() : [];

        return $this->field('image_urls', $label, 'textarea', $options, implode("\n", $model?->image_urls ?? []), false);
    }

    protected function form(string $label, string $action, array $fields = [], ?int $id = null): array
    {
        return compact('label', 'action', 'fields', 'id');
    }

    protected function link(string $label, string $slug, array $params = []): array
    {
        return ['label' => $label, 'url' => route('ui.show', ['slug' => $slug, 'context' => $this->request->attributes->get('ui.context')] + $params + ($this->workspace ? ['workspace_id' => $this->workspace->id] : []))];
    }

    protected function can(string $permission, ?int $property = null, ?int $room = null): bool
    {
        if ($this->user && ! $this->workspace) {
            return $this->access->personal($this->user, $permission);
        }

        return $this->user && $this->workspace && ($this->access->allowed($this->user, $permission, $this->workspace, $property, $room) || ($property === null && $this->access->propertyIds($this->user, $this->workspace, $permission) !== []));
    }

    protected function rooms(string $permission = 'property.view')
    {
        return $this->workspace ? $this->scope->rooms($this->user, $this->workspace, $permission) : Room::whereIn('id', $this->scope->currentRoomIds($this->user));
    }

    protected function roomOptions(string $permission): array
    {
        return $this->rooms($permission)->with('property')->get()->mapWithKeys(fn ($r) => [$r->id => $r->property->name.' · '.$r->name])->all();
    }

    protected function propertyOptions(string $permission): array
    {
        return Property::whereIn('id', $this->workspace ? $this->access->propertyIds($this->user, $this->workspace, $permission) : [])->pluck('name', 'id')->all();
    }

    protected function rentalOptions(string $permission): array
    {
        return $this->scope->rentals($this->user, $this->workspace, $permission)->with('room', 'tenant')->whereIn('status', ['active', 'move_out_pending'])->get()->mapWithKeys(fn ($r) => [$r->id => $r->room->name.' · '.$r->tenant->name])->all();
    }

    protected function records($query, callable $map, string $pageName = 'page'): void
    {
        $model=$query->getModel();$selected=$this->request->query('item');
        if($selected&&RecordPresentation::specification($this->request,$this->request->route('slug')??'' )&&preg_match('/^'.preg_quote($model->getTable(),'/').':([1-9][0-9]*)$/',$selected,$match))$query->whereKey($match[1]);
        $order=$this->request->validate(['order'=>'nullable|in:newest,oldest'])['order']??null;
        if($order)$query->reorder($model->qualifyColumn($model->getKeyName()),$order==='oldest'?'asc':'desc');
        $query->orderByDesc($query->getModel()->qualifyColumn($query->getModel()->getKeyName()));
        $pager = $query->paginate(config('business.pagination'), ['*'], $pageName,$selected?1:null)->withQueryString();
        $housing = [];
        foreach ($pager->getCollection() as $record) {
            if ($record instanceof Property || $record instanceof Room || $record instanceof Listing) {
                $housing[] = $record;
                if ($record instanceof Room) {
                    $housing[] = $record->property;
                }
                if ($record instanceof Listing) {
                    $housing[] = $record->room;
                    $housing[] = $record->room->property;
                }
            }
        }
        if ($housing) {
            app(HousingMedia::class)->preload($housing);
        }
        $this->vm['records'] = $pager->getCollection()->map(fn($record)=>$map($record)+['_key'=>$record->getTable().':'.$record->getKey()])->all();
        $this->vm['_record_sortable']=true;
        $this->vm['pagination'] = $pager;
    }

    protected function rentalPolicyForm(string $level, $model): array
    {
        $policy = $model instanceof Room ? app(RentalPolicy::class)->forRoom($model) : array_replace(collect(config('rental_workflow'))->except('statuses')->all(), $model instanceof Property ? ($model->workspace->rental_policy ?? []) : [], $model->rental_policy ?? []);
        $fields = [$this->field('level', '', 'hidden', value: $level)];
        if ($level !== 'workspace') {
            $fields[] = $this->field($level === 'room' ? 'room_id' : 'property_id', '', 'hidden', value: $model->id);
        }
        foreach (['hold_hours' => 'Thời gian giữ phòng (giờ)', 'extension_hours' => 'Tối đa mỗi lần gia hạn (giờ)', 'exception_hours' => 'Thời gian chờ xử lý ngoại lệ (giờ)'] as $key => $label) {
            $fields[] = $this->field($key, $label, 'number', value: $policy[$key]);
        }
        $fields[] = $this->field('partial_deposit', 'Thanh toán cọc', 'select', [0 => 'Một lần', 1 => 'Cho phép từng phần'], (int) $policy['partial_deposit']);
        $fields[] = $this->field('deposit_terms', 'Điều khoản hoàn và khấu trừ tiền cọc', 'textarea', value: $policy['deposit_terms']);

        return $this->form('Chính sách giữ phòng và tiền cọc', 'rental.policy', $fields, $model->id);
    }

    protected function card(string $title, string $subtitle = '', array $facts = [], array $forms = [], array $links = []): array
    {
        return compact('title', 'subtitle', 'facts', 'forms', 'links');
    }

    public function present(Request $request, string $slug): array
    {
        $this->request = $request;
        $this->user = $request->user();
        $this->workspace = $request->attributes->get('workspace');
        $this->vm = ['forms' => [], 'records' => [], 'links' => [], 'stats' => [], 'notice' => null, 'pagination' => null, 'marketplace' => false];
        $query = $request->validate(['q' => 'nullable|string|max:150', 'id' => 'nullable|integer|min:1', 'min_price' => 'nullable|integer|min:0', 'max_price' => 'nullable|integer|min:0', 'min_area' => 'nullable|numeric|min:0', 'district' => 'nullable|string|max:100', 'sort' => 'nullable|in:newest,price_asc,price_desc', 'status' => 'nullable|string|max:40']);
        $q = $query['q'] ?? '';
        $this->build($slug, $q);
        if ($this->user) {
            $filter = function ($node) use (&$filter) {
                if (! is_array($node)) {
                    return $node;
                }
                if (isset($node['action'], $node['fields'])) {
                    foreach ($node['fields'] as $field) {
                        if ($field['required'] && in_array($field['type'], ['select', 'room-select', 'search-select'], true) && $field['options'] === []) {
                            return null;
                        }
                    }
                    $data = ['id' => $node['id'] ?? null];
                    foreach ($node['fields'] as $field) {
                        $data[$field['name']] = $field['value'];
                    }

                    return app(ActionAuthorizer::class)->allows($this->user, $node['action'], $data, $this->workspace, true) ? $node : null;
                }
                $result = [];
                foreach ($node as $key => $value) {
                    $value = $filter($value);
                    if ($value !== null || ! is_array($node[$key]) || ! isset($node[$key]['action'], $node[$key]['fields'])) {
                        $result[$key] = $value;
                    }
                }

                return array_is_list($node) ? array_values($result) : $result;
            };
            $this->vm = $filter($this->vm);
        }

        return $this->vm;
    }

    abstract protected function build(string $slug, string $q): void;
}
