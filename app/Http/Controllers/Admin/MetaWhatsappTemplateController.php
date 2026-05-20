<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaWhatsappTemplate;
use App\Services\MetaWhatsAppTemplateService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MetaWhatsappTemplateController extends Controller
{
    public function __construct(private readonly MetaWhatsAppTemplateService $service)
    {
    }

    public function index(Request $request)
    {
        $query = MetaWhatsappTemplate::query()->orderBy('name');
        $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search, $likeOperator): void {
                $q->where('name', $likeOperator, "%{$search}%")
                    ->orWhere('meta_template_id', 'like', "%{$search}%");
            });
        }

        $perPage = (int) ($request->input('per_page') ?? 100);
        $templates = $query->paginate($perPage);

        return response()->json([
            'data' => $templates->getCollection()->map(function (MetaWhatsappTemplate $t) {
                return [
                    'id' => $t->id,
                    'meta_template_id' => $t->meta_template_id,
                    'name' => $t->name,
                    'language' => $t->language,
                    'status' => $t->status,
                    'category' => $t->category,
                    'sub_category' => $t->sub_category,
                    'components' => $t->components,
                    'last_synced_at' => optional($t->last_synced_at)->toISOString(),
                    'updated_at' => optional($t->updated_at)->toISOString(),
                ];
            })->values(),
            'meta' => [
                'total' => $templates->total(),
                'page' => $templates->currentPage(),
                'per_page' => $templates->perPage(),
            ],
        ]);
    }

    public function sync(Request $request)
    {
        try {
            $result = $this->service->syncToDatabase();

            AuditLogger::log(
                action: 'admin.meta_templates.synced',
                subject: null,
                payload: ['synced' => $result['synced'], 'total' => $result['total']],
            );

            return response()->json([
                'message' => 'Sync template berhasil.',
                'data' => $result,
            ]);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}
