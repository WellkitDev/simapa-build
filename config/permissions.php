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
                'refund' => ['order.refund.form', 'order.refund.store', 'order.refund.pdf'],
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

        'manuscript' => [
            'label'   => 'Papan Manuskrip',
            'actions' => [
                'view'   => ['manuscript.board'],
                // "Lihat detail progres satu judul" — order.indexJudul.detail/.progress dan
                // title.progress.logs SAMA-SAMA tanpa middleware role: sama sekali hari ini
                // (terbuka utk semua role yang login), beda dgn manuscript.board yang dijaga
                // role:production|manager|superadmin. Dipisah jadi action sendiri supaya satu
                // permission tidak mencampur route berpenjagaan dgn route tanpa penjagaan.
                // (order.indexJudul.progress masih di sini sampai progressDetail dipensiunkan.)
                'detail' => ['order.indexJudul.detail', 'order.indexJudul.progress', 'title.progress.logs'],
            ],
        ],

        'distribution' => [
            'label'   => 'Distribusi Naskah',
            'actions' => [
                'view'     => ['distribusi.artikel.index', 'distribusi.artikel.show',
                               'distribusi.buku.index', 'distribusi.buku.show'],
                'assign'   => ['distribusi.artikel.editor', 'distribusi.buku.editorSemua',
                               'distribusi.buku.chapter.editor'],
                'move'     => ['distribusi.artikel.tahap', 'distribusi.buku.chapter.tahap'],
                'priority' => ['distribusi.artikel.prioritas', 'distribusi.buku.prioritas'],
                'target'   => ['distribusi.artikel.target', 'distribusi.buku.target'],
                'upload'   => ['distribusi.artikel.file', 'distribusi.buku.file',
                               'distribusi.buku.chapter.file'],
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

        'permission' => [
            'label'   => 'Hak Akses',
            'actions' => ['manage' => ['permission.index', 'permission.update']],
        ],
    ],
];
