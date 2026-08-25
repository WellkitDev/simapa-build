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
        // Detail + utas aktivitas: yang menjaganya authorizeTask() (pelaksana, pemberi,
        // atau manager), bukan izin peran — sama seperti sunting dan hapus di atas.
        'task.show', 'task.report',
        'report.daily', 'report.note', 'report.submit',
        'report.files.store', 'report.files.destroy', 'report.monthly',
        // Slip gaji milik-sendiri (self-service) — terbuka utk semua user login.
        'salary.slip.me', 'salary.slip.me.pdf',
    ],

    'modules' => [
        'order' => [
            'label'   => 'Order',
            'actions' => [
                'view'   => ['order.book.index', 'order.book.indexJudul', 'order.book.show',
                             'order.journal.show'],
                'create' => ['order.book.create', 'order.book.store',
                             'order.journal.create', 'order.journal.store'],
                'edit'   => ['order.book.edit', 'order.book.update',
                             'order.journal.edit', 'order.journal.update'],
                'refund' => ['order.refund.form', 'order.refund.store', 'order.refund.pdf',
                             'order.refund.undo'],
                'cancel'  => ['order.cancel'],
                'restore' => ['order.restore'],
            ],
        ],

        'payment' => [
            'label'   => 'Pembayaran',
            'actions' => [
                'view'    => ['payment.index', 'payment.dp.index', 'payment.fp.index'],
                'create'  => ['payment.create', 'payment.store'],
                'edit'    => ['payment.update'],
                'approve' => ['payment.approve', 'payment.reject'],
            ],
        ],

        'invoice' => [
            'label'   => 'Invoice',
            'actions' => [
                'view'   => ['invoice.index', 'invoice.show', 'invoice.logs'],
                'edit'   => ['invoice.edit', 'invoice.update', 'invoice.updateStatus'],
                'cancel' => ['invoice.cancel'],
                'export' => ['invoice.pdf'],
            ],
        ],

        'tagihan' => [
            'label'   => 'Tagihan',
            'actions' => [
                'view'    => ['tagihan.index', 'tagihan.show'],
                'create'  => ['tagihan.create', 'tagihan.store', 'tagihan.buatOrder'],
                'edit'    => ['tagihan.edit', 'tagihan.update'],
                'approve' => ['tagihan.approve', 'tagihan.reject'],
                'cancel'  => ['tagihan.cancel'],
                'export'  => ['tagihan.pdf'],
            ],
        ],

        'income' => [
            'label'   => 'Pemasukan',
            'actions' => [
                'view'   => ['income.pemasukan', 'income.piutang', 'income.lunas'],
                'export' => ['income.pemasukan.pdf', 'income.pemasukan.csv',
                             'income.piutang.pdf', 'income.piutang.csv',
                             'income.lunas.pdf', 'income.lunas.csv'],
            ],
        ],

        'marketing-target' => [
            'label'   => 'Target Marketing',
            'actions' => [
                'view'   => ['marketing-target.index'],
                'create' => ['marketing-target.store'],
                'edit'   => ['marketing-target.paid'],
                'delete' => ['marketing-target.destroy'],
                // marketing-target.me ("Target Saya") dijaga role:marketing|manager|superadmin
                // aslinya — beda dari view/create/edit/delete di atas (manager|superadmin saja,
                // marketing TIDAK ikut). Dipisah jadi action sendiri karena satu permission
                // tidak bisa mewakili dua tingkat akses berbeda.
                'me'     => ['marketing-target.me'],
            ],
        ],

        'title' => [
            'label'   => 'Direktori Judul',
            'actions' => [
                'view'    => ['title.index', 'title.show'],
                'create'  => ['title.create', 'title.store'],
                'edit'    => ['title.edit', 'title.update'],
                'delete'  => ['title.destroy'],
                'deactivate' => ['title.deactivate', 'title.activate'],
                'submit'  => ['title.submit'],
                'approve' => ['title.approve', 'title.reject'],
                'info'    => ['title.info.update', 'title.chapters.authors'],
            ],
        ],

        'title.doc' => [
            'label'   => 'Judul — Kelengkapan Dokumen',
            'actions' => [
                'edit'   => ['title.doc.save'],
                'submit' => ['title.doc.submit'],
            ],
        ],

        'doc-req' => [
            'label'   => 'Template Checklist Dokumen',
            'actions' => [
                'create' => ['doc-req.store'],
                'edit'   => ['doc-req.update'],
                'delete' => ['doc-req.destroy'],
            ],
        ],

        'author' => [
            'label'   => 'Direktori Penulis',
            'actions' => [
                'view' => ['author.index', 'author.show'],
            ],
        ],

        'journal' => [
            'label'   => 'Direktori Jurnal',
            'actions' => [
                'view'       => ['journal.index', 'journal.show'],
                'create'     => ['journal.create', 'journal.store'],
                'edit'       => ['journal.edit', 'journal.update'],
                'delete'     => ['journal.destroy'],
                'submission' => ['journal.submission.store', 'journal.submission.update',
                                 'journal.submission.destroy'],
            ],
        ],

        'isbn' => [
            'label'   => 'Direktori ISBN',
            'actions' => [
                'view'   => ['isbn.index'],
                'create' => ['isbn.store'],
                'edit'   => ['isbn.update'],
                'delete' => ['isbn.destroy'],
            ],
        ],

        // Sisa modul manuskrip lama: papan Kanban & Distribusi Artikel/Buku dihapus
        // 2026-08-10 (digantikan modul naskah di bawah). Yang tersisa hanya "lihat detail
        // progres satu judul", karena route-nya milik halaman Order/Direktori Judul —
        // bukan bagian dari modul yang dihapus — dan terbuka untuk semua role yang login.
        'manuscript' => [
            'label'   => 'Detail Progres Judul',
            'actions' => [
                'detail' => ['order.indexJudul.detail', 'order.indexJudul.progress', 'title.progress.logs'],
            ],
        ],

        // Modul Penugasan Naskah (pengganti UI Distribusi Artikel/Buku + Papan Manuskrip).
        // Matriks role per spec docs/superpowers/specs/2026-08-09-penugasan-naskah-design.md §4;
        // permission manuscript.*/distribution.* lama DIBIARKAN sampai cutover (Task 14).
        'naskah' => [
            'label'   => 'Penugasan Naskah',
            'actions' => [
                // Pelacakan + detail + arsip: semua role (marketing read-only, tanpa blok aksi).
                'view'     => ['naskah.pelacakan', 'naskah.show', 'naskah.arsip', 'naskah.berkas'],
                // Meja Kerja Saya: production/admin (marketing TIDAK — tak punya tugas naskah).
                'workdesk' => ['naskah.workdesk'],
                // Set target publish/terbit: marketing (request klien, tercatat) + admin.
                'target'   => ['naskah.target'],
                // Upload file naskah: semua role (marketing = naskah masuk dari klien;
                // pelaksana = bukti kerja pemicu auto-advance; admin = hasil per tahap).
                // `revisi.hasil` sengaja di kelompok ini, bukan `advance`: jawaban revisi
                // boleh datang dari Pelaksana, dan `advance` tertutup untuknya.
                'upload'   => ['naskah.file', 'naskah.bab.file', 'naskah.revisi.hasil'],
                // Ambil tugas dari antrian tanpa pelaksana: production.
                'claim'    => ['naskah.claim', 'naskah.bab.claim'],
                // Distribusi/tarik pelaksana + oper PJ antar admin sebidang: admin (bidangnya).
                'assign'   => ['naskah.distribusi', 'naskah.tarik', 'naskah.operPj',
                               'naskah.bab.distribusi', 'naskah.bab.pelaksanaSemua'],
                // Satu tombol "Selesaikan tahap →" (maju 1 langkah) + "Perlu Revisi": admin.
                'advance'  => ['naskah.selesaikan', 'naskah.kembalikan', 'naskah.bab.selesaikan',
                               'naskah.revisi.minta', 'naskah.revisi.tutup'],
                'priority' => ['naskah.prioritas'],
                'hold'     => ['naskah.hold'],
                'cancel'   => ['naskah.batal'],
                // Koreksi mundur/lompat, termasuk membuka tahap final: superadmin SAJA
                // (masuk daftar $superadminOnly di AccessMatrixSeeder).
                'correct'  => ['naskah.koreksi'],
                // Pemetaan author per bab (wajib sebelum bab bisa didistribusikan) —
                // dilakukan marketing/admin saat struktur buku dibuat.
                'author'   => ['naskah.bab.author'],
                // Susunan bab buku (tambah/ubah judul/hapus bab kosong). Dipisah dari
                // 'author' supaya nama permission tetap jujur menyebut apa yang diaturnya,
                // walau penerima hibahnya kebetulan sama (marketing + admin).
                'struktur' => ['naskah.bab.struktur'],
            ],
        ],

        'archive' => [
            'label'   => 'Arsip Judul',
            'actions' => [
                'view'      => ['archive.index', 'archive.show', 'archive.pdf'],
                'artifacts' => ['archive.artifacts'],
                'submit'    => ['archive.submit'],
                'approve'   => ['archive.approve', 'archive.reject'],
            ],
        ],

        'announcement' => [
            'label'   => 'Pengumuman',
            'actions' => [
                'view'   => ['announcement.index'],
                'create' => ['announcement.create', 'announcement.store'],
                'edit'   => ['announcement.edit', 'announcement.update'],
                'delete' => ['announcement.destroy'],
                'status' => ['announcement.status'],
            ],
        ],

        'task.monitor' => [
            'label'   => 'Papan Tugas (Monitor Tim)',
            'actions' => [
                'view' => ['task.monitor'],
            ],
        ],

        'report.submissions' => [
            'label'   => 'Rekap Setoran Laporan',
            'actions' => [
                'view' => ['report.submissions'],
            ],
        ],

        'user' => [
            'label'   => 'Manajemen User',
            'actions' => [
                'view'    => ['user.management'],
                'create'  => ['user.management.store'],
                'edit'    => ['user.management.update'],
                'delete'  => ['user.management.destroy', 'user.management.forceDelete'],
                'restore' => ['user.management.restore'],
            ],
        ],

        'data' => [
            'label'   => 'Gudang Data',
            'actions' => [
                'view'     => ['data.index'],
                'create'   => ['data.create', 'data.store'],
                'edit'     => ['data.edit', 'data.update'],
                'delete'   => ['data.destroy'],
                'download' => ['data.download'],
            ],
        ],

        'accounting.overview' => [
            'label'   => 'Keuangan — Ringkasan',
            'actions' => [
                'view' => ['accounting.overview'],
            ],
        ],

        'accounting.journal' => [
            'label'   => 'Keuangan — Jurnal Kas',
            'actions' => [
                'view'     => ['accounting.journal'],
                'create'   => ['accounting.entry.store'],
                'edit'     => ['accounting.entry.update'],
                'delete'   => ['accounting.entry.destroy'],
                'export'   => ['accounting.journal.export.csv', 'accounting.journal.export.pdf'],
                'transfer' => ['accounting.transfer.store'],
            ],
        ],

        'accounting.master' => [
            'label'   => 'Keuangan — Master Akun & Kategori',
            'actions' => [
                'create' => ['accounting.account.store', 'accounting.category.store'],
                'edit'   => ['accounting.account.update', 'accounting.category.update'],
                'delete' => ['accounting.account.destroy', 'accounting.category.destroy'],
            ],
        ],

        'accounting.recap' => [
            'label'   => 'Keuangan — Rekap & Dashboard',
            'actions' => [
                'view'   => ['accounting.dashboard'],
                'export' => ['accounting.recap.export.csv', 'accounting.recap.export.pdf'],
            ],
        ],

        'accounting.distribution' => [
            'label'   => 'Keuangan — Distribusi Profit',
            'actions' => [
                'view' => ['accounting.distribution'],
                'edit' => ['accounting.distribution.settings',
                           'accounting.distribution.rule.store',
                           'accounting.distribution.rule.update',
                           'accounting.distribution.rule.destroy'],
            ],
        ],

        'accounting.assumption' => [
            'label'   => 'Keuangan — Asumsi',
            'actions' => [
                'view'   => ['accounting.assumption'],
                'create' => ['accounting.assumption.margin.store', 'accounting.assumption.expense.store'],
                'edit'   => ['accounting.assumption.margin.update', 'accounting.assumption.expense.update'],
                'delete' => ['accounting.assumption.margin.destroy', 'accounting.assumption.expense.destroy'],
            ],
        ],

        'accounting.target' => [
            'label'   => 'Keuangan — Anggaran & Target',
            'actions' => [
                'view' => ['accounting.target'],
                'edit' => ['accounting.target.update'],
            ],
        ],

        'accounting.period' => [
            'label'   => 'Keuangan — Kunci Periode',
            'actions' => [
                'lock' => ['accounting.period.lock', 'accounting.period.unlock'],
            ],
        ],

        'accounting.audit' => [
            'label'   => 'Keuangan — Audit',
            'actions' => [
                'view' => ['accounting.audit'],
            ],
        ],

        'accounting.profit' => [
            'label'   => 'Keuangan — Analisa Profit',
            'actions' => [
                'view' => ['accounting.profit'],
            ],
        ],

        'salary' => [
            'label'   => 'Slip Gaji',
            'actions' => [
                'view'   => ['salary.slip.index', 'salary.slip.show'],
                'create' => ['salary.slip.create', 'salary.slip.store'],
                'edit'   => ['salary.slip.edit', 'salary.slip.update'],
                'delete' => ['salary.slip.destroy'],
                'send'   => ['salary.slip.send'],
                'export' => ['salary.slip.pdf'],
            ],
        ],

        'service_invoice' => [
            'label'   => 'Invoice Layanan',
            'actions' => [
                'view'   => ['service.invoice.index', 'service.invoice.show'],
                'create' => ['service.invoice.create', 'service.invoice.store'],
                'edit'   => ['service.invoice.edit', 'service.invoice.update'],
                'delete' => ['service.invoice.destroy'],
                'status'  => ['service.invoice.status'],
                'cancel'  => ['service.invoice.cancel'],
                'payment' => ['service.invoice.payment.store', 'service.invoice.payment.destroy'],
                'export' => ['service.invoice.pdf'],
                'send'   => ['service.invoice.send'],
            ],
        ],

        'service_catalog' => [
            'label'   => 'Katalog Layanan',
            'actions' => [
                'view'   => ['service.catalog.index'],
                'manage' => ['service.catalog.store', 'service.catalog.update', 'service.catalog.destroy'],
            ],
        ],

        'service_client' => [
            'label'   => 'Klien Jasa',
            'actions' => [
                'view'   => ['service.client.index', 'service.client.show'],
                'manage' => ['service.client.store', 'service.client.update', 'service.client.destroy'],
            ],
        ],

        'permission' => [
            'label'   => 'Hak Akses',
            'actions' => ['manage' => ['permission.index', 'permission.update']],
        ],
    ],
];
