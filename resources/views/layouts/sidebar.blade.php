<nav class="sidebar">
    <div class="sidebar-header">
        <a href="#" class="sidebar-brand">Si<span>MAPA</span></a>
        <div class="sidebar-toggler not-active">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </div>
    <div class="sidebar-body">
        <ul class="nav">

            {{-- ===================== UTAMA ===================== --}}
            <li class="nav-item nav-category">Menu Utama</li>
            <li class="nav-item {{ nav_active('dashboard') }}">
                <a href="{{ route('dashboard') }}" class="nav-link">
                    <i class="link-icon" data-feather="home"></i>
                    <span class="link-title">Dashboard</span>
                </a>
            </li>

            {{-- ===================== ORDER ===================== --}}
            @canany(['order.create', 'order.view'])
                <li class="nav-item nav-category">Order</li>
                @can('order.create')
                    <li class="nav-item {{ nav_active(['order.book.create', 'order.journal.create']) }}">
                        <a class="nav-link" data-bs-toggle="collapse" href="#menuOrder" role="button"
                            aria-expanded="{{ nav_expanded(['order.book.create', 'order.journal.create']) }}" aria-controls="menuOrder">
                            <i class="link-icon" data-feather="plus-square"></i>
                            <span class="link-title">Buat Order</span>
                            <i class="link-arrow" data-feather="chevron-down"></i>
                        </a>
                        <div class="collapse {{ nav_show(['order.book.create', 'order.journal.create']) }}" id="menuOrder">
                            <ul class="nav sub-menu">
                                <li class="nav-item"><a href="{{ route('order.book.create') }}" class="nav-link {{ nav_active('order.book.create') }}">Buku</a></li>
                                <li class="nav-item"><a href="{{ route('order.journal.create') }}" class="nav-link {{ nav_active('order.journal.create') }}">Jurnal</a></li>
                            </ul>
                        </div>
                    </li>
                @endcan
                @can('order.view')
                    <li class="nav-item {{ nav_active(['order.book.index', 'order.book.show', 'order.book.edit', 'order.book.update', 'order.journal.show', 'order.journal.edit', 'order.journal.update', 'order.refund.*']) }}">
                        <a href="{{ route('order.book.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="shopping-bag"></i>
                            <span class="link-title">Daftar Order</span>
                        </a>
                    </li>
                @endcan
            @endcanany

            {{-- ===================== PEMBAYARAN ===================== --}}
            @canany(['tagihan.view', 'invoice.view', 'payment.view'])
                <li class="nav-item nav-category">Pembayaran</li>
                @can('tagihan.view')
                    <li class="nav-item {{ nav_active('tagihan.*') }}">
                        <a href="{{ route('tagihan.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="file-plus"></i>
                            <span class="link-title">Tagihan</span>
                        </a>
                    </li>
                @endcan
                @can('invoice.view')
                    <li class="nav-item {{ nav_active('invoice.*') }}">
                        <a href="{{ route('invoice.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="file-text"></i>
                            <span class="link-title">Invoice</span>
                        </a>
                    </li>
                @endcan
                @can('payment.view')
                    <li class="nav-item {{ nav_active('payment.*') }}">
                        <a class="nav-link" data-bs-toggle="collapse" href="#menuPayment" role="button"
                            aria-expanded="{{ nav_expanded('payment.*') }}" aria-controls="menuPayment">
                            <i class="link-icon" data-feather="credit-card"></i>
                            <span class="link-title">Pembayaran</span>
                            <i class="link-arrow" data-feather="chevron-down"></i>
                        </a>
                        <div class="collapse {{ nav_show('payment.*') }}" id="menuPayment">
                            <ul class="nav sub-menu">
                                <li class="nav-item"><a href="{{ route('payment.dp.index') }}" class="nav-link {{ nav_active('payment.dp.index') }}">DP/Pembayaran</a></li>
                                <li class="nav-item"><a href="{{ route('payment.fp.index') }}" class="nav-link {{ nav_active('payment.fp.index') }}">Pelunasan</a></li>
                                <li class="nav-item"><a href="{{ route('payment.index') }}" class="nav-link {{ nav_active('payment.index') }}">Persetujuan</a></li>
                            </ul>
                        </div>
                    </li>
                @endcan
            @endcanany

            {{-- ===================== DIREKTORI ===================== --}}
            @canany(['title.view', 'journal.view', 'isbn.view', 'author.view', 'archive.view'])
                <li class="nav-item nav-category">Direktori</li>
                @can('title.view')
                    <li class="nav-item {{ nav_active('title.*') }}">
                        <a href="{{ route('title.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="book"></i>
                            <span class="link-title">Direktori Judul</span>
                        </a>
                    </li>
                @endcan
                @can('journal.view')
                    <li class="nav-item {{ nav_active('journal.*') }}">
                        <a href="{{ route('journal.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="book-open"></i>
                            <span class="link-title">Direktori Jurnal</span>
                        </a>
                    </li>
                @endcan
                @can('isbn.view')
                    <li class="nav-item {{ nav_active('isbn.*') }}">
                        <a href="{{ route('isbn.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="hash"></i>
                            <span class="link-title">Direktori ISBN</span>
                        </a>
                    </li>
                @endcan
                @can('author.view')
                    <li class="nav-item {{ nav_active('author.*') }}">
                        <a href="{{ route('author.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="users"></i>
                            <span class="link-title">Direktori Author</span>
                        </a>
                    </li>
                @endcan
                @can('archive.view')
                    <li class="nav-item {{ nav_active('archive.*') }}">
                        <a href="{{ route('archive.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="archive"></i>
                            <span class="link-title">Arsip Judul</span>
                        </a>
                    </li>
                @endcan
            @endcanany

            {{-- ===================== NASKAH =====================
                 Nama menu SAMA untuk semua role (keputusan tim: sedikit istilah,
                 tanpa jargon). Meja Kerja hanya untuk yang benar-benar memegang tugas. --}}
            @canany(['naskah.view', 'naskah.workdesk'])
                <li class="nav-item nav-category">Naskah</li>
                @can('naskah.workdesk')
                    <li class="nav-item {{ nav_active('naskah.workdesk') }}">
                        <a href="{{ route('naskah.workdesk') }}" class="nav-link">
                            <i class="link-icon" data-feather="clipboard"></i>
                            <span class="link-title">Meja Kerja Saya</span>
                        </a>
                    </li>
                @endcan
                @can('naskah.view')
                    <li class="nav-item {{ nav_active(['naskah.pelacakan', 'naskah.show']) }}">
                        <a href="{{ route('naskah.pelacakan') }}" class="nav-link">
                            <i class="link-icon" data-feather="git-branch"></i>
                            <span class="link-title">Pelacakan Naskah</span>
                        </a>
                    </li>
                    <li class="nav-item {{ nav_active('naskah.arsip') }}">
                        <a href="{{ route('naskah.arsip') }}" class="nav-link">
                            <i class="link-icon" data-feather="inbox"></i>
                            <span class="link-title">Arsip Naskah</span>
                        </a>
                    </li>
                @endcan
            @endcanany

            {{-- ===================== KEUANGAN ===================== --}}
            @canany(['accounting.overview.view', 'accounting.journal.view', 'accounting.recap.view', 'accounting.distribution.view', 'accounting.assumption.view', 'accounting.target.view', 'accounting.profit.view', 'accounting.audit.view'])
                <li class="nav-item nav-category">Keuangan</li>
                @can('accounting.overview.view')
                    <li class="nav-item {{ nav_active('accounting.overview') }}">
                        <a href="{{ route('accounting.overview') }}" class="nav-link">
                            <i class="link-icon" data-feather="pie-chart"></i>
                            <span class="link-title">Ringkasan</span>
                        </a>
                    </li>
                @endcan
                @can('accounting.journal.view')
                    <li class="nav-item {{ nav_active('accounting.journal') }}">
                        <a href="{{ route('accounting.journal') }}" class="nav-link">
                            <i class="link-icon" data-feather="dollar-sign"></i>
                            <span class="link-title">Jurnal Kas</span>
                        </a>
                    </li>
                @endcan
                @can('accounting.recap.view')
                    <li class="nav-item {{ nav_active('accounting.dashboard') }}">
                        <a href="{{ route('accounting.dashboard') }}" class="nav-link">
                            <i class="link-icon" data-feather="bar-chart-2"></i>
                            <span class="link-title">Dashboard Keuangan</span>
                        </a>
                    </li>
                @endcan
                @can('accounting.distribution.view')
                    <li class="nav-item {{ nav_active('accounting.distribution') }}">
                        <a href="{{ route('accounting.distribution') }}" class="nav-link">
                            <i class="link-icon" data-feather="share-2"></i>
                            <span class="link-title">Distribusi Profit</span>
                        </a>
                    </li>
                @endcan
                @can('accounting.assumption.view')
                    <li class="nav-item {{ nav_active('accounting.assumption') }}">
                        <a href="{{ route('accounting.assumption') }}" class="nav-link">
                            <i class="link-icon" data-feather="sliders"></i>
                            <span class="link-title">Asumsi</span>
                        </a>
                    </li>
                @endcan
                @can('accounting.target.view')
                    <li class="nav-item {{ nav_active('accounting.target') }}">
                        <a href="{{ route('accounting.target') }}" class="nav-link">
                            <i class="link-icon" data-feather="target"></i>
                            <span class="link-title">Anggaran & Target</span>
                        </a>
                    </li>
                @endcan
                @can('accounting.profit.view')
                    <li class="nav-item {{ nav_active('accounting.profit') }}">
                        <a href="{{ route('accounting.profit') }}" class="nav-link">
                            <i class="link-icon" data-feather="trending-up"></i>
                            <span class="link-title">Analisa Profit</span>
                        </a>
                    </li>
                @endcan
                @can('accounting.audit.view')
                    <li class="nav-item {{ nav_active('accounting.audit') }}">
                        <a href="{{ route('accounting.audit') }}" class="nav-link">
                            <i class="link-icon" data-feather="clock"></i>
                            <span class="link-title">Riwayat Perubahan</span>
                        </a>
                    </li>
                @endcan
            @endcanany

            {{-- Slip Gaji (admin: superadmin/accounting) — izin salary.view di luar grup accounting.* --}}
            @can('salary.view')
                <li class="nav-item {{ nav_active('salary.slip.index') }}">
                    <a href="{{ route('salary.slip.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="file-text"></i>
                        <span class="link-title">Slip Gaji</span>
                    </a>
                </li>
            @endcan

            {{-- ===================== LAPORAN ===================== --}}
            {{-- Header + Laporan Harian/Bulanan tanpa penjaga: report.daily/report.monthly publik,
                 dan header perlu terlihat kapan pun ada item di bawahnya (termasuk keduanya) yang tampil. --}}
            <li class="nav-item nav-category">Laporan</li>
            @can('income.view')
                <li class="nav-item {{ nav_active('income.*') }}">
                    <a class="nav-link" data-bs-toggle="collapse" href="#menuIncome" role="button"
                        aria-expanded="{{ nav_expanded('income.*') }}" aria-controls="menuIncome">
                        <i class="link-icon" data-feather="trending-up"></i>
                        <span class="link-title">Laporan Keuangan</span>
                        <i class="link-arrow" data-feather="chevron-down"></i>
                    </a>
                    <div class="collapse {{ nav_show('income.*') }}" id="menuIncome">
                        <ul class="nav sub-menu">
                            <li class="nav-item"><a href="{{ route('income.pemasukan') }}" class="nav-link {{ nav_active('income.pemasukan') }}">Pemasukan</a></li>
                            <li class="nav-item"><a href="{{ route('income.piutang') }}" class="nav-link {{ nav_active('income.piutang') }}">Piutang</a></li>
                            <li class="nav-item"><a href="{{ route('income.lunas') }}" class="nav-link {{ nav_active('income.lunas') }}">Order Selesai</a></li>
                        </ul>
                    </div>
                </li>
            @endcan
            @can('marketing-target.view')
                <li class="nav-item {{ nav_active('marketing-target.index') }}">
                    <a href="{{ route('marketing-target.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="crosshair"></i>
                        <span class="link-title">Target Marketing</span>
                    </a>
                </li>
            @endcan
            @can('marketing-target.me')
                <li class="nav-item {{ nav_active('marketing-target.me') }}">
                    <a href="{{ route('marketing-target.me') }}" class="nav-link">
                        <i class="link-icon" data-feather="crosshair"></i>
                        <span class="link-title">Target Saya</span>
                    </a>
                </li>
            @endcan
            {{-- report.daily / report.monthly: route publik (config/permissions.php 'public'),
                 terbuka utk semua role yang login — tanpa penjaga @can. --}}
            <li class="nav-item {{ nav_active('report.daily') }}">
                <a href="{{ route('report.daily') }}" class="nav-link">
                    <i class="link-icon" data-feather="file"></i>
                    <span class="link-title">Laporan Harian</span>
                </a>
            </li>
            <li class="nav-item {{ nav_active('report.monthly') }}">
                <a href="{{ route('report.monthly') }}" class="nav-link">
                    <i class="link-icon" data-feather="bar-chart"></i>
                    <span class="link-title">Laporan Bulanan</span>
                </a>
            </li>
            {{-- salary.slip.me: route publik (own-data), terbuka utk semua role login — tanpa penjaga @can. --}}
            <li class="nav-item {{ nav_active('salary.slip.me') }}">
                <a href="{{ route('salary.slip.me') }}" class="nav-link">
                    <i class="link-icon" data-feather="file-text"></i>
                    <span class="link-title">Slip Gaji Saya</span>
                </a>
            </li>
            @can('report.submissions.view')
                <li class="nav-item {{ nav_active('report.submissions') }}">
                    <a href="{{ route('report.submissions') }}" class="nav-link">
                        <i class="link-icon" data-feather="clipboard"></i>
                        <span class="link-title">Pemantauan Laporan</span>
                    </a>
                </li>
            @endcan

            {{-- ===================== TUGAS ===================== --}}
            <li class="nav-item nav-category">Tugas</li>
            <li class="nav-item {{ nav_active('task.board') }}">
                <a href="{{ route('task.board') }}" class="nav-link">
                    <i class="link-icon" data-feather="trello"></i>
                    <span class="link-title">Papan Tugas</span>
                </a>
            </li>
            <li class="nav-item {{ nav_active('task.calendar') }}">
                <a href="{{ route('task.calendar') }}" class="nav-link">
                    <i class="link-icon" data-feather="calendar"></i>
                    <span class="link-title">Kalender</span>
                </a>
            </li>
            <li class="nav-item {{ nav_active('task.index') }}">
                <a href="{{ route('task.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="check-square"></i>
                    <span class="link-title">Daftar Tugas</span>
                </a>
            </li>
            @can('task.monitor.view')
                <li class="nav-item {{ nav_active('task.monitor') }}">
                    <a href="{{ route('task.monitor') }}" class="nav-link">
                        <i class="link-icon" data-feather="activity"></i>
                        <span class="link-title">Pemantauan Tugas</span>
                    </a>
                </li>
            @endcan

            {{-- ===================== ALAT ===================== --}}
            <li class="nav-item nav-category">Alat</li>
            <li class="nav-item {{ nav_active('data.*') }}">
                <a href="{{ route('data.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="database"></i>
                    <span class="link-title">Gudang Data</span>
                </a>
            </li>

            {{-- ===================== AKUN & SISTEM ===================== --}}
            <li class="nav-item nav-category">Akun &amp; Sistem</li>
            @can('user.view')
                <li class="nav-item {{ nav_active('user.management') }}">
                    <a href="{{ route('user.management') }}" class="nav-link">
                        <i class="link-icon" data-feather="users"></i>
                        <span class="link-title">Manajemen User</span>
                    </a>
                </li>
            @endcan
            @can('permission.manage')
                <li class="nav-item {{ nav_active('permission.index') }}">
                    <a href="{{ route('permission.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="shield"></i>
                        <span class="link-title">Hak Akses</span>
                    </a>
                </li>
            @endcan
            @can('announcement.view')
                <li class="nav-item {{ nav_active(['announcement.index', 'announcement.create', 'announcement.edit']) }}">
                    <a href="{{ route('announcement.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="volume-2"></i>
                        <span class="link-title">Pengumuman</span>
                    </a>
                </li>
            @endcan
            <li class="nav-item {{ nav_active('profile') }}">
                <a href="{{ route('profile') }}" class="nav-link">
                    <i class="link-icon" data-feather="user"></i>
                    <span class="link-title">Profil</span>
                </a>
            </li>
        </ul>
    </div>
</nav>


<nav class="massege">
    <!-- errors message -->
    @if (session('error'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show">
            <div class="alert alert-danger alert-dismissible" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="btn-close"></button>
            </div>
        </div>
    @endif
    @if (session('success'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show">
            <div class="alert alert-success alert-dismissible" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="btn-close"></button>
            </div>
        </div>
    @endif
    @if (session('status'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show">
            <div class="alert alert-success alert-dismissible" role="alert">
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="btn-close"></button>
            </div>
        </div>
    @endif
</nav>
