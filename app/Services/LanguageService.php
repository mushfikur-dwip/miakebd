<?php

namespace App\Services;


use App\Http\Requests\LanguageFileTextGetRequest;
use App\Libraries\AppLibrary;
use Exception;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\LanguageRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Dipokhalder\Settings\Facades\Settings;


class LanguageService
{

    protected $languageFilter = [
        'name',
        'code',
        'status',
    ];
    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return Language::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->languageFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(LanguageRequest $request)
    {
        try {
            if (!file_exists(base_path("resources/js/languages/{$request->code}.json"))) {
                copy(base_path("resources/js/languages/en.json"), base_path("resources/js/languages/{$request->code}.json"));
            }

            if (!file_exists(base_path("lang/{$request->code}"))) {
                mkdir(base_path("lang/{$request->code}"), 0755);
                $files = scandir(base_path("lang/en"));
                if (count($files) > 2) {
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..') {
                            copy(base_path("lang/en/{$file}"), base_path("lang/{$request->code}/{$file}"));
                        }
                    }
                }
            }

            $language = Language::create($request->validated());
            if ($request->image) {
                $language->addMediaFromRequest('image')->toMediaCollection('language');
            }

            return $language;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(LanguageRequest $request, Language $language): Language
    {
        try {
            $language->update($request->validated());
            if ($request->image) {
                $language->clearMediaCollection('language');
                $language->addMediaFromRequest('image')->toMediaCollection('language');
            }
            return $language;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Language $language): void
    {
        try {
            if (Settings::group('site')->get("site_default_language") != $language->id) {
                if (!env('DEMO')) {
                    AppLibrary::deleteDir(base_path("lang/{$language->code}"));
                    if (file_exists(base_path("resources/js/languages/{$language->code}.json"))) {
                        unlink(base_path("resources/js/languages/{$language->code}.json"));
                    }
                }
                $language->delete();
            } else {
                throw new Exception("Default language not deletable", 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Language $language): Language
    {
        try {
            return $language;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    /**
     * @throws Exception
     */
    public function fileList(Language $language)
    {
        try {
            $i = 0;
            $array = [];

            if (file_exists(base_path("resources/js/languages/{$language->code}.json"))) {
                $array[$i] = (object)[
                    'path' => base_path("resources/js/languages/{$language->code}.json"),
                    'name' => "{$language->code}.json"
                ];
                $i++;
            }

            if (file_exists(base_path("lang/{$language->code}"))) {
                $files = scandir(base_path("lang/{$language->code}"));
                if (count($files) > 2) {
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..') {
                            $array[$i] = (object)[
                                'path' => base_path("lang/{$language->code}/{$file}"),
                                'name' => $file
                            ];
                            $i++;
                        }
                    }
                }
            }
            return collect($array);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    /**
     * The only paths this feature ever legitimately touches are the two
     * directories fileList() builds its list from.
     *
     * Both this method and fileTextStore() took an absolute path straight off
     * the request. fileText() then include()s it, and fileTextStore() writes to
     * it - between them, read-anything and write-anything on the server, which
     * is a route from the settings permission to running code. realpath()
     * resolves any ../ before the comparison, so traversal cannot walk out of
     * the allowed directories.
     */
    private function assertLanguageFilePath(?string $path): string
    {
        $resolved = $path ? realpath($path) : false;

        if ($resolved === false) {
            throw new Exception(trans('all.message.language_file_invalid'), 422);
        }

        $allowed = [
            realpath(base_path('lang')),
            realpath(base_path('resources/js/languages')),
        ];

        foreach ($allowed as $directory) {
            if ($directory && str_starts_with($resolved, $directory . DIRECTORY_SEPARATOR)) {
                return $resolved;
            }
        }

        throw new Exception(trans('all.message.language_file_invalid'), 422);
    }

    /**
     * @throws Exception
     */
    public function fileText(LanguageFileTextGetRequest $request)
    {
        $path = $this->assertLanguageFilePath($request->path);

        $explodeName = explode('.', $request->name);
        if (count($explodeName) > 1) {
            if ($explodeName[1] == 'json') {
                include($path);
            } else {
                return include($path);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function fileTextStore(Request $request): void
    {
        try {
            $path = $this->assertLanguageFilePath($request->x_language_file_path);

            $file = fopen($path, "rw");
            $fileContent = file_get_contents($path);
            foreach ($request->all() as $key => $value) {
                if ($key != 'x_language_file_path' && $key != 'x_language_file_name') {
                    $key = str_replace('_', ' ', $key);
                    if (strpos($fileContent, "'" . $key . "'") !== false) {
                        $fileContent = str_replace("'" . $key . "'", "\"{$value}\"", $fileContent);
                    } elseif (strpos($fileContent, "\"{$key}\"") !== false) {
                        $fileContent = str_replace("\"{$key}\"", "\"{$value}\"", $fileContent);
                    }
                }
            }

            file_put_contents($path, $fileContent);
            fclose($file);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
