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
            <li class="nav-item nav-category">Main</li>
            <li class="nav-item {{ active_class(['dashboard']) }}">
                <a href="{{ route('dashboard') }}" class="nav-link">
                    <i class="link-icon" data-feather="box"></i>
                    <span class="link-title">Dashboard</span>
                </a>
            </li>

            {{-- @role(['superadmin', 'manager', 'marketing'])
                <li class="nav-item nav-category">Management Order</li>
                <li class="nav-item {{ active_class(['order-books', 'order-books/*']) }}">
                    <a href="{{ route('order.book.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="users"></i>
                        <span class="link-title">Books</span>
                    </a>
                </li>
            @endrole

            @role(['superadmin', 'manager'])
                <li class="nav-item nav-category">Management</li>
                <li class="nav-item {{ active_class(['user-management']) }}">
                    <a href="{{ route('user.management') }}" class="nav-link">
                        <i class="link-icon" data-feather="users"></i>
                        <span class="link-title">User Management</span>
                    </a>
                </li>
            @endrole --}}
            <li class="nav-item nav-category">Management</li>
            <li class="nav-item {{ active_class(['order/*']) }}">
                <a class="nav-link" data-bs-toggle="collapse" href="#order" role="button"
                    aria-expanded="{{ is_active_route(['order/*']) }}" aria-controls="order">
                    <i class="link-icon" data-feather="shopping-cart"></i>
                    <span class="link-title">Order</span>
                    <i class="link-arrow" data-feather="chevron-down"></i>
                </a>
                <div class="collapse {{ show_class(['order/*']) }}" id="order">
                    <ul class="nav sub-menu">
                        <li class="nav-item">
                            <a href="{{ route('order.book.create') }}"
                                class="nav-link {{ active_class(['order/buku/*']) }}">Buku</a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('order.journal.create') }}"
                                class="nav-link {{ active_class(['order/jurnal/*']) }}">Jurnal</a>
                        </li>
                    </ul>
                </div>
            </li>
            {{-- <li class="nav-item {{ active_class(['books', 'books/create']) }}">
                <a href="{{ route('order.book.create') }}" class="nav-link">
                    <i class="link-icon" data-feather="shopping-cart"></i>
                    <span class="link-title">Book</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['journal', 'journal/create']) }}">
                <a href="{{ route('order.journal.create') }}" class="nav-link">
                    <i class="link-icon" data-feather="shopping-cart"></i>
                    <span class="link-title">Journal</span>
                </a>
            </li> --}}
            <li class="nav-item {{ active_class(['management', 'management/*']) }}">
                <a href="{{ route('order.book.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="list"></i>
                    <span class="link-title">List</span>
                </a>
            </li>
            <li class="nav-item nav-category">Payment</li>
            <li class="nav-item {{ active_class(['payments', 'payments/dp']) }}">
                <a href="{{ route('payment.dp.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="credit-card"></i>
                    <span class="link-title">Debt Payment</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['payments', 'payments/full']) }}">
                <a href="{{ route('payment.fp.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="shopping-bag"></i>
                    <span class="link-title">Full Payment</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['payments', 'payments/list']) }}">
                <a href="{{ route('payment.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="list"></i>
                    <span class="link-title">Approved</span>
                </a>
            </li>
            {{-- <li class="nav-item nav-category">Books</li>

            <li class="nav-item ">
                <a href="" class="nav-link">
                    <i class="link-icon" data-feather="repeat"></i>
                    <span class="link-title">Refund</span>
                </a>
            </li> --}}
            {{-- <li class="nav-item {{ active_class(['order-books', 'order-books/approval']) }}">
                <a href="{{ route('order.book.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="package"></i>
                    <span class="link-title">Approval</span>
                </a>
            </li> --}}
            <li class="nav-item nav-category">Income</li>
            <li class="nav-item {{ active_class(['income', 'income/order']) }}">
                <a href="{{ route('income.order') }}" class="nav-link">
                    <i class="link-icon" data-feather="package"></i>
                    <span class="link-title">Order</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['income', 'income/payment']) }}">
                <a href="{{ route('income.payment') }}" class="nav-link">
                    <i class="link-icon" data-feather="package"></i>
                    <span class="link-title">Payment</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['income', 'income/pending']) }}">
                <a href="{{ route('income.pending') }}" class="nav-link">
                    <i class="link-icon" data-feather="package"></i>
                    <span class="link-title">Pending</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['income', 'income/full-payment']) }}">
                <a href="{{ route('income.lunas') }}" class="nav-link">
                    <i class="link-icon" data-feather="package"></i>
                    <span class="link-title">Full Payment</span>
                </a>
            </li>
            <li class="nav-item nav-category">Account</li>
            <li class="nav-item {{ active_class(['profile']) }}">
                <a href="{{ route('profile') }}" class="nav-link">
                    <i class="link-icon" data-feather="user"></i>
                    <span class="link-title">Profile</span>
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
