<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="">
    <meta name="author" content="">
    <meta name="keywords" content="">

    <title>@yield('title', 'SiMAPA - Sistem Manajemen Project Avidpedia')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <!-- End fonts -->

    <!-- CSRF Token -->
    <meta name="_token" content="{{ csrf_token() }}">

    <link rel="shortcut icon" href="{{ asset('assets/images/favicon.png') }}">

    <!-- plugin css -->
    <link href="{{ asset('assets/fonts/feather-font/css/iconfont.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/plugins/perfect-scrollbar/perfect-scrollbar.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/plugins/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet" />
    <!-- end plugin css -->

    @stack('plugin-styles')

    <!-- common css -->
    <link href="{{ asset('css/app.css') }}" rel="stylesheet" />

    <!-- end common css -->

    @stack('style')
</head>

<body data-base-url="{{ url('/') }}">

    <script src="{{ asset('assets/js/spinner.js') }}"></script>

    <div class="main-wrapper" id="app">
        @include('layouts.sidebar')
        <div class="page-wrapper">
            @include('layouts.header')
            <div class="page-content">
                @yield('content')
            </div>
            @include('layouts.footer')
        </div>
    </div>

    <!-- base js -->
    <script src="{{ asset('js/app.js') }}"></script>
    <script src="{{ asset('assets/plugins/feather-icons/feather.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/perfect-scrollbar/perfect-scrollbar.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/sweetalert2/sweetalert2.min.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@2.8.2/dist/alpine.min.js"></script>
    <!-- end base js -->

    <!-- plugin js -->
    @stack('plugin-scripts')
    <!-- end plugin js -->

    <!-- common js -->
    <script src="{{ asset('assets/js/template.js') }}"></script>
    <!-- end common js -->

    {{-- Bahasa Indonesia default untuk semua DataTables (dimuat setelah plugin, sebelum init per-halaman) --}}
    <script>
        if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
            jQuery.extend(true, jQuery.fn.dataTable.defaults, {
                language: {
                    emptyTable: "Tidak ada data.",
                    zeroRecords: "Tidak ada data yang cocok.",
                    info: "Menampilkan _START_–_END_ dari _TOTAL_ data",
                    infoEmpty: "Menampilkan 0 dari 0 data",
                    infoFiltered: "(disaring dari _MAX_ total data)",
                    lengthMenu: "Tampilkan _MENU_ data",
                    search: "Cari:",
                    processing: "Memproses...",
                    loadingRecords: "Memuat...",
                    paginate: { first: "Pertama", last: "Terakhir", next: "Berikutnya", previous: "Sebelumnya" },
                    aria: { sortAscending: ": aktifkan untuk urut naik", sortDescending: ": aktifkan untuk urut turun" }
                }
            });
        }
    </script>

    @stack('custom-scripts')

    <script>
    (function () {
        if (!window.Swal) return;
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.matches || !form.matches('[data-confirm]') || form.dataset.confirmed === '1') return;
            e.preventDefault();
            Swal.fire({
                title: 'Konfirmasi', text: form.getAttribute('data-confirm'), icon: 'warning',
                showCancelButton: true, confirmButtonText: 'Ya', cancelButtonText: 'Batal', confirmButtonColor: '#d33'
            }).then(function (res) { if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); } });
        }, true);
        window.swalError = function (msg) { Swal.fire({ icon: 'error', title: 'Gagal', text: msg }); };
        window.swalSuccess = function (msg) { Swal.fire({ icon: 'success', title: 'Berhasil', text: msg, timer: 2000, showConfirmButton: false }); };
        @if(session('success')) window.swalSuccess(@json(session('success'))); @endif
        @if(session('error')) window.swalError(@json(session('error'))); @endif
    })();
    </script>
</body>

</html>
