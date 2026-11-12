<?php

return [
    'sensor_readings' => ['smoke_ppm' => ['label' => 'Nồng độ khói', 'unit' => 'ppm'], 'temperature_c' => ['label' => 'Nhiệt độ', 'unit' => '°C'], 'gas' => ['label' => 'Chỉ số khí (0–1)', 'unit' => ''], 'temperature' => ['label' => 'Nhiệt độ', 'unit' => '°C']],
    'payment_reminder_days' => 3,
    'roommate_budget_tolerance' => 1000000, 'forecast_min_days' => 14, 'forecast_min_events' => 30,
    'pagination' => 12, 'heartbeat_seconds' => 60, 'missed_heartbeats' => 3, 'watermark_seconds' => 300,
    'trial_days' => 14, 'trial_retention_days' => 7, 'paid_grace_days' => 7,
    'limits' => ['trial' => ['rooms' => 5, 'staff' => 1], 'monthly' => ['rooms' => 100, 'staff' => 10], 'yearly' => ['rooms' => 1000, 'staff' => 50]],
    'continuity' => ['iot.view', 'iot.ack', 'rental.view', 'invoice.view', 'maintenance.view', 'maintenance.manage'],
    'workspace_permissions' => ['property.view', 'property.manage', 'listing.manage', 'connection.manage', 'rental.view', 'rental.manage', 'invoice.view', 'invoice.manage', 'payment.record', 'maintenance.view', 'maintenance.manage', 'iot.view', 'iot.manage', 'iot.ack', 'member.manage', 'subscription.manage', 'analytics.view'],
    'non_delegable' => ['subscription.manage'],
];
