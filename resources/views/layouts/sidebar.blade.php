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
            <li class="nav-item nav-category">Menu Utama</li>
            <li class="nav-item {{ active_class(['dashboard']) }}">
                <a href="{{ route('dashboard') }}" class="nav-link">
                    <i class="link-icon" data-feather="box"></i>
                    <span class="link-title">Dashboard</span>
                </a>
            </li>

            @role(['superadmin', 'manager', 'marketing'])
                <li class="nav-item nav-category">Pembayaran</li>
                <li class="nav-item {{ request()->routeIs('tagihan.*') ? 'active' : '' }}">
                    <a href="{{ route('tagihan.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="file-plus"></i>
                        <span class="link-title">Tagihan</span>
                    </a>
                </li>
                <li class="nav-item {{ request()->routeIs('invoice.*') ? 'active' : '' }}">
                    <a href="{{ route('invoice.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="file-text"></i>
                        <span class="link-title">Invoice</span>
                    </a>
                </li>
                <li class="nav-item {{ active_class(['payments/*']) }}">
                    <a class="nav-link" data-bs-toggle="collapse" href="#menuPayment" role="button"
                        aria-expanded="{{ is_active_route(['payments/*']) }}" aria-controls="menuPayment">
                        <i class="link-icon" data-feather="credit-card"></i>
                        <span class="link-title">Pembayaran</span>
                        <i class="link-arrow" data-feather="chevron-down"></i>
                    </a>
                    <div class="collapse {{ show_class(['payments/*']) }}" id="menuPayment">
                        <ul class="nav sub-menu">
                            <li class="nav-item"><a href="{{ route('payment.dp.index') }}" class="nav-link">DP/Pembayaran</a></li>
                            <li class="nav-item"><a href="{{ route('payment.fp.index') }}" class="nav-link">Pelunasan</a></li>
                            <li class="nav-item"><a href="{{ route('payment.index') }}" class="nav-link">Disetujui</a></li>
                        </ul>
                    </div>
                </li>
            @endrole

            @role(['superadmin', 'manager', 'marketing'])
                <li class="nav-item nav-category">Order &amp; Naskah</li>
                <li class="nav-item {{ active_class(['order/*']) }}">
                    <a class="nav-link" data-bs-toggle="collapse" href="#menuOrder" role="button"
                        aria-expanded="{{ is_active_route(['order/*']) }}" aria-controls="menuOrder">
                        <i class="link-icon" data-feather="shopping-cart"></i>
                        <span class="link-title">Buat Order</span>
                        <i class="link-arrow" data-feather="chevron-down"></i>
                    </a>
                    <div class="collapse {{ show_class(['order/*']) }}" id="menuOrder">
                        <ul class="nav sub-menu">
                            <li class="nav-item">
                                <a href="{{ route('order.book.create') }}" class="nav-link {{ active_class(['order/buku/*']) }}">Buku</a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('order.journal.create') }}" class="nav-link {{ active_class(['order/jurnal/*']) }}">Jurnal</a>
                            </li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item {{ active_class(['management/order']) }}">
                    <a href="{{ route('order.book.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="list"></i>
                        <span class="link-title">Daftar Order</span>
                    </a>
                </li>
                <li class="nav-item {{ active_class(['management/archive', 'management/archive/*']) }}">
                    <a href="{{ route('archive.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="archive"></i>
                        <span class="link-title">Arsip Judul</span>
                    </a>
                </li>
            @endrole

            @role(['superadmin', 'manager', 'admin', 'production', 'marketing'])
                <li class="nav-item {{ active_class(['titles', 'titles/*']) }}">
                    <a href="{{ route('title.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="book"></i>
                        <span class="link-title">Direktori Judul</span>
                    </a>
                </li>
            @endrole

            @role(['superadmin', 'manager', 'admin', 'production', 'marketing'])
                <li class="nav-item {{ active_class(['journals', 'journals/*']) }}">
                    <a href="{{ route('journal.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="book-open"></i>
                        <span class="link-title">Direktori Jurnal</span>
                    </a>
                </li>
            @endrole

            @role(['superadmin', 'manager', 'admin', 'production', 'marketing'])
                <li class="nav-item {{ active_class(['management/isbn', 'management/isbn/*']) }}">
                    <a href="{{ route('isbn.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="hash"></i>
                        <span class="link-title">Direktori ISBN</span>
                    </a>
                </li>
            @endrole

            @role(['superadmin', 'manager', 'production'])
                <li class="nav-item nav-category">Produksi</li>
                <li class="nav-item {{ active_class(['management/manuscript']) }}">
                    <a href="{{ route('manuscript.board') }}" class="nav-link">
                        <i class="link-icon" data-feather="trello"></i>
                        <span class="link-title">{{ (auth()->user()->hasRole('production') && ! auth()->user()->hasAnyRole(['manager','superadmin'])) ? 'Meja Kerja Saya' : 'Manuscript Tracker' }}</span>
                    </a>
                </li>
            @endrole

            @role(['superadmin', 'manager', 'marketing'])
                <li class="nav-item nav-category">Laporan</li>
                <li class="nav-item {{ active_class(['income/*']) }}">
                    <a class="nav-link" data-bs-toggle="collapse" href="#menuIncome" role="button"
                        aria-expanded="{{ is_active_route(['income/*']) }}" aria-controls="menuIncome">
                        <i class="link-icon" data-feather="bar-chart-2"></i>
                        <span class="link-title">Keuangan</span>
                        <i class="link-arrow" data-feather="chevron-down"></i>
                    </a>
                    <div class="collapse {{ show_class(['income/*']) }}" id="menuIncome">
                        <ul class="nav sub-menu">
                            <li class="nav-item"><a href="{{ route('income.pemasukan') }}" class="nav-link">Pemasukan</a></li>
                            <li class="nav-item"><a href="{{ route('income.piutang') }}" class="nav-link">Piutang</a></li>
                            <li class="nav-item"><a href="{{ route('income.lunas') }}" class="nav-link">Order Selesai</a></li>
                        </ul>
                    </div>
                </li>
                @role(['superadmin', 'manager'])
                    <li class="nav-item {{ active_class(['marketing-target']) }}">
                        <a href="{{ route('marketing-target.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="target"></i>
                            <span class="link-title">Target Marketing</span>
                        </a>
                    </li>
                @endrole
                @role(['marketing'])
                    <li class="nav-item {{ active_class(['target']) }}">
                        <a href="{{ route('marketing-target.me') }}" class="nav-link">
                            <i class="link-icon" data-feather="target"></i>
                            <span class="link-title">Target Saya</span>
                        </a>
                    </li>
                @endrole
            @endrole

            <li class="nav-item nav-category">Tugas</li>
            <li class="nav-item {{ active_class(['tasks/board']) }}">
                <a href="{{ route('task.board') }}" class="nav-link">
                    <i class="link-icon" data-feather="trello"></i>
                    <span class="link-title">Board Tugas</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['tasks/calendar']) }}">
                <a href="{{ route('task.calendar') }}" class="nav-link">
                    <i class="link-icon" data-feather="calendar"></i>
                    <span class="link-title">Kalender</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['tasks']) }}">
                <a href="{{ route('task.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="check-square"></i>
                    <span class="link-title">Todo</span>
                </a>
            </li>
            @role(['manager', 'superadmin'])
                <li class="nav-item {{ active_class(['tasks/monitor']) }}">
                    <a href="{{ route('task.monitor') }}" class="nav-link">
                        <i class="link-icon" data-feather="activity"></i>
                        <span class="link-title">Pemantauan Tugas</span>
                    </a>
                </li>
            @endrole

            <li class="nav-item nav-category">Report</li>
            <li class="nav-item {{ active_class(['reports/daily']) }}">
                <a href="{{ route('report.daily') }}" class="nav-link">
                    <i class="link-icon" data-feather="file-text"></i>
                    <span class="link-title">Report Harian</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['reports/monthly']) }}">
                <a href="{{ route('report.monthly') }}" class="nav-link">
                    <i class="link-icon" data-feather="calendar"></i>
                    <span class="link-title">Report Bulanan</span>
                </a>
            </li>
            @role(['manager', 'superadmin'])
                <li class="nav-item {{ active_class(['reports/submissions']) }}">
                    <a href="{{ route('report.submissions') }}" class="nav-link">
                        <i class="link-icon" data-feather="clipboard"></i>
                        <span class="link-title">Pemantauan Report</span>
                    </a>
                </li>
            @endrole

            <li class="nav-item nav-category">Akun</li>
            @role(['superadmin', 'manager'])
                <li class="nav-item {{ active_class(['user-management']) }}">
                    <a href="{{ route('user.management') }}" class="nav-link">
                        <i class="link-icon" data-feather="users"></i>
                        <span class="link-title">Manajemen User</span>
                    </a>
                </li>
            @endrole
            @role(['superadmin', 'manager', 'admin'])
                <li class="nav-item {{ active_class(['announcements']) }}">
                    <a href="{{ route('announcement.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="volume-2"></i>
                        <span class="link-title">Pengumuman</span>
                    </a>
                </li>
            @endrole
            <li class="nav-item {{ active_class(['profile']) }}">
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
