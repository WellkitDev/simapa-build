<?php
// Sumber kebenaran tunggal hak akses. Halaman Hak Akses DAN middleware sama-sama membaca berkas ini,
// sehingga UI tak mungkin menjanjikan sesuatu yang tidak ditegakkan.
//
// 'public'  = route yang cukup terautentikasi (milik-sendiri / lintas-role), tanpa permission.
// 'modules' = modul => ['label' => ..., 'actions' => ['aksi' => [nama route, ...]]]
//             Nama permission yang dihasilkan = "<kunci modul>.<aksi>".
return [
    'public' => [
        'dashboard', 'profile', 'profile.image',
        'notifications.index', 'notifications.read', 'notifications.readAll',
        'announcement.seen',
        // Tugas & laporan PRIBADI — didaftar satu per satu, BUKAN wildcard, karena
        // task.monitor & report.submissions justru harus berizin.
        'task.index', 'task.board', 'task.calendar', 'task.events', 'task.reorder',
        'task.store', 'task.update', 'task.destroy', 'task.status', 'task.schedule',
        'report.daily', 'report.note', 'report.submit',
        'report.files.store', 'report.files.destroy', 'report.monthly',
        'marketing-target.me',
    ],

    'modules' => [
        // Diisi pada Task 2, dituntun PermissionMapCompletenessTest.
    ],
];
