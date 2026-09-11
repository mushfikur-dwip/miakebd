<?php

namespace App\Services;

use App\Libraries\QueryExceptionLibrary;
use Exception;
use Illuminate\Http\Request;
use App\Models\NotificationAlert;
use Illuminate\Support\Facades\Log;

class NotificationAlertService
{
    /**
     * @throws Exception
     */
    public function list(): \Illuminate\Database\Eloquent\Collection
    {
        try {
            return NotificationAlert::all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(Request $request): \Illuminate\Database\Eloquent\Collection
    {
        try {
            $type        = $request->type;
            // Real ids, not range(1, count). A row added later - the POS order
            // message - or a gap left by a deleted row would fall outside that
            // range, and its switch and message would silently never save.
            $numberArray = NotificationAlert::pluck('id')->all();
            $typeArray   = array_map(fn($id) => $type . $id, $numberArray);

            $data         = $request->only($numberArray);
            $option_Value = $request->only($typeArray);

            $id      = [];
            $message = [];
            $option  = [];

            foreach ($data as $key => $msg) {
                array_push($id, $key);
                array_push($message, $msg);
            }

            foreach ($option_Value as $value) {
                array_push($option, $value);
            }

            $notificationAlerts = [
                'id'      => $id,
                'message' => $message,
                'option'  => $option
            ];
            foreach ($notificationAlerts['id'] as $key => $notificationAlert) {
                NotificationAlert::where('id', $notificationAlert)->update([
                    $type . '_message' => $notificationAlerts['message'][$key],
                    $type              => $notificationAlerts['option'][$key],
                ]);
            }
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
