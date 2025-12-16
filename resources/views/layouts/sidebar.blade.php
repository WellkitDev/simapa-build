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
            {{-- <li class="nav-item {{ active_class(['dashboard']) }}">
                <a href="{{ route('dashboard') }}" class="nav-link">
                    <i class="link-icon" data-feather="box"></i>
                    <span class="link-title">Dashboard</span>
                </a>
            </li> --}}

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
