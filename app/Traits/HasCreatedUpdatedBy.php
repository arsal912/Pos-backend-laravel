<?php

namespace App\Traits;

trait HasCreatedUpdatedBy
{
    public static function bootHasCreatedUpdatedBy(): void
    {
        static::creating(function ($model) {
            $userId = auth()->id();
            if ($userId) {
                if (isset($model->created_by) === false || $model->created_by === null) {
                    $model->created_by = $userId;
                }
                $model->updated_by = $userId;
            }
        });

        static::updating(function ($model) {
            $userId = auth()->id();
            if ($userId) {
                $model->updated_by = $userId;
            }
        });
    }
}
