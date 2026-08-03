<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * This file is intentionally named HardwareDeviceModel to avoid conflicts
 * with the HardwareDevice model. This contains reference data for device models.
 */
class HardwareDeviceModel extends Model
{
    protected $table = 'hardware_device_models';
    protected $guarded = ['id'];

    // Predefined device models
    public static $MODELS = [
        'biometric' => [
            'ZKTeco' => ['name' => 'ZKTeco MB460', 'type' => 'biometric'],
            'Suprema' => ['name' => 'Suprema RealScan', 'type' => 'biometric'],
            'Anviz' => ['name' => 'Anviz EF500', 'type' => 'biometric'],
        ],
        'rfid' => [
            'Nexus' => ['name' => 'Nexus RFID Reader', 'type' => 'rfid'],
            'Kingjim' => ['name' => 'Kingjim RF3100', 'type' => 'rfid'],
        ],
        'qr' => [
            'Generic' => ['name' => 'USB QR Code Scanner', 'type' => 'qr_code'],
        ],
    ];
}
