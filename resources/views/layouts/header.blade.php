<nav class="navbar">
    <a href="#" class="sidebar-toggler">
        <i data-feather="menu"></i>
    </a>
    <div class="navbar-content">
        <ul class="navbar-nav">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="profileDropdown" role="button"
                    data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    @if (!Auth::user()->profile->profile_picture_url)
                        <img class="wd-30 ht-30 rounded-circle" src="{{ asset('assets/images/others/user.svg') }}"
                            alt="profile">
                    @else
                        <img class="wd-30 ht-30 rounded-circle"
                            src="{{ route('profile.image', Auth::user()->profile->profile_picture_id) }}"
                            alt="profile">
                    @endif
                </a>
                <div class="dropdown-menu p-0" aria-labelledby="profileDropdown">
                    <div class="d-flex flex-column align-items-center border-bottom px-5 py-3">
                        <div class="mb-3">
                            @if (!Auth::user()->profile->profile_picture_url)
                                <img class="wd-80 ht-80 rounded-circle"
                                    src="{{ asset('assets/images/others/user.svg') }}" alt="">
                            @else
                                <img class="wd-80 ht-80 rounded-circle"
                                    src="{{ route('profile.image', Auth::user()->profile->profile_picture_id) }}"
                                    alt="">
                            @endif

                        </div>
                        <div class="text-center">
                            <p class="tx-16 fw-bolder">{{ Auth::user()->username }}</p>
                            <p class="tx-12 text-muted">{{ Auth::user()->email }}</p>
                        </div>
                    </div>
                    <ul class="list-unstyled p-1">
                        <li class="dropdown-item py-2">
                            <a href="{{ route('profile') }}" class="text-body ms-0">
                                <i class="me-2 icon-md" data-feather="user"></i>
                                <span>Profile</span>
                            </a>
                        </li>
                        <li class="dropdown-item py-2">
                            <a href="javascript:;" class="text-body ms-0">
                                <i class="me-2 icon-md" data-feather="edit"></i>
                                <span>Edit Profile</span>
                            </a>
                        </li>
                        <li class="dropdown-item py-2">
                            <a href="javascript:;" class="text-body ms-0">
                                <i class="me-2 icon-md" data-feather="repeat"></i>
                                <span>Switch User</span>
                            </a>
                        </li>
                        <li class="dropdown-item py-2">
                            <form action="{{ route('logout') }}" method="POST">
                                @csrf
                                <button type="submit" class="bt-logout text-body ms-0"><i class="me-2 icon-md"
                                        data-feather="log-out"></i>
                                    <span>Log Out</span></button>
                            </form>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>
</nav>
