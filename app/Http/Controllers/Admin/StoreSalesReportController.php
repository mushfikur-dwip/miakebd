<?php

namespace App\Http\Controllers\Admin;

use App\Exports\StoreSalesReportExport;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\StoreSalesReportBranchSummaryResource;
use App\Http\Resources\StoreSalesReportOverviewResource;
use App\Http\Resources\StoreSalesReportResource;
use App\Models\ThemeSetting;
use App\Services\CompanyService;
use App\Services\StoreSalesReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Dipokhalder\Settings\Facades\Settings;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;

class StoreSalesReportController extends AdminController implements HasMiddleware
{
    private StoreSalesReportService $storeSalesReportService;
    private CompanyService $companyService;

    public function __construct(StoreSalesReportService $storeSalesReportService, CompanyService $companyService)
    {
        parent::__construct();
        $this->storeSalesReportService = $storeSalesReportService;
        $this->companyService          = $companyService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:store-sales-report', only: ['index', 'overview', 'branchSummary', 'export', 'exportPdf']),
        ];
    }

    public function index(PaginateRequest $request)
    {
        try {
            return StoreSalesReportResource::collection($this->storeSalesReportService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function overview(Request $request)
    {
        try {
            return new StoreSalesReportOverviewResource($this->storeSalesReportService->overview($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function branchSummary(Request $request)
    {
        try {
            return StoreSalesReportBranchSummaryResource::collection($this->storeSalesReportService->branchSummary($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request)
    {
        try {
            return Excel::download(new StoreSalesReportExport($this->storeSalesReportService, $request), 'Store-Sales-Report.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function exportPdf(PaginateRequest $request): mixed
    {
        try {
            $company       = $this->companyService->list();
            $copyright     = Settings::group('site')->get('site_copyright');
            $orders        = $this->storeSalesReportService->list($request);
            $branchSummary = $this->storeSalesReportService->branchSummary($request);

            $imagePath = ThemeSetting::where(['key' => 'theme_logo'])->first()?->logo;
            $response  = Http::withOptions(['verify' => false])->get($imagePath);
            $themeLogo = 'data:image/png;base64,' . base64_encode($response->body());

            $pdf = Pdf::loadView('reports.storeSalesReport', compact('company', 'themeLogo', 'orders', 'branchSummary', 'copyright'))
                ->setPaper('a4')->setOption(['defaultFont' => 'Urbanist']);

            return response()->stream(
                fn() => print($pdf->output()),
                200,
                [
                    'Content-Type'        => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="store_sales_report.pdf"',
                ]
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
