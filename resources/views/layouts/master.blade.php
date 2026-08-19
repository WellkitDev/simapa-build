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

    <style>
        /* Judul panjang di DataTables: batasi lebar & bungkus ke bawah (bukan memanjang horizontal) */
        table.dataTable td.dt-judul,
        table td.dt-judul {
            white-space: normal !important;
            max-width: 320px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        /*
         | Gulir mendatar kedua di ATAS tabel, menempel di bawah navbar.
         |
         | Gulir bawaan .table-responsive duduk di tepi BAWAH tabel. Pada tabel
         | panjang, satu-satunya cara menggeser kolom adalah menggulir halaman
         | sampai dasar tabel dulu — persis saat baris yang ingin dilihat sudah
         | keluar layar. Salinan yang menempel ini membuat kendali geser selalu
         | ada di tempat mata sedang bekerja.
         |
         | Gulir bawaan di bawah sengaja DIBIARKAN: ia tetap berguna saat orang
         | memang sudah berada di dasar tabel, dan keduanya tersinkron.
         */
        .gulir-tabel-atas {
            position: sticky;
            top: 60px;          /* tinggi .page-wrapper .navbar yang position:fixed */
            z-index: 970;       /* di bawah navbar (978), di atas isi kartu */
            overflow-x: auto;
            overflow-y: hidden;
            height: 14px;
            margin-bottom: 0.35rem;
            background: #fff;
            border-bottom: 1px solid #f2f4f9;
        }

        .gulir-tabel-atas > div {
            height: 1px;
        }

        /* Batang gulir tipis tapi masih cukup lebar untuk ditarik dengan tetikus. */
        .gulir-tabel-atas::-webkit-scrollbar {
            height: 10px;
        }

        .gulir-tabel-atas::-webkit-scrollbar-thumb {
            background: #c3cbd8;
            border-radius: 6px;
        }

        .gulir-tabel-atas::-webkit-scrollbar-thumb:hover {
            background: #a7b1c2;
        }

        .gulir-tabel-atas::-webkit-scrollbar-track {
            background: #f2f4f9;
            border-radius: 6px;
        }

        .gulir-tabel-atas {
            scrollbar-width: thin;                 /* Firefox */
            scrollbar-color: #c3cbd8 #f2f4f9;
        }

        /* Saat dicetak, kendali gulir tak punya arti. */
        @media print {
            .gulir-tabel-atas { display: none !important; }
        }
    </style>

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
    <!-- idempotency guard -->
    <script src="{{ asset('js/idempotency.js') }}"></script>
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
            }).then(function (res) {
                if (!res.isConfirmed) return;
                form.dataset.confirmed = '1';
                // form.submit() melewati event submit, jadi penguncian tombol unggah
                // harus dipanggil sendiri di jalur ini.
                if (window.kunciTombolUnggah) window.kunciTombolUnggah(form);
                form.submit();
            });
        }, true);
        window.swalError = function (msg) { Swal.fire({ icon: 'error', title: 'Gagal', text: msg }); };
        window.swalSuccess = function (msg) { Swal.fire({ icon: 'success', title: 'Berhasil', text: msg, timer: 2000, showConfirmButton: false }); };
        window.swalInfo = function (msg) { Swal.fire({ icon: 'info', title: 'Info', text: msg }); };

        /*
         | Galat validasi. Sebelumnya TIDAK pernah ditampilkan di layout mana pun: setiap
         | validate() yang gagal hanya memuat ulang halaman tanpa keterangan, sehingga
         | penolakan yang wajar (format file salah, ukuran lewat batas, isian kosong)
         | terbaca sebagai "gagal tanpa sebab". Disalurkan lewat kanal yang sama dengan
         | flash lain supaya seluruh halaman kebagian tanpa menyentuh view satu per satu.
         */
        window.swalValidation = function (messages) {
            Swal.fire({
                icon: 'error',
                title: messages.length > 1 ? 'Ada ' + messages.length + ' isian yang perlu diperbaiki' : 'Gagal',
                html: '<ul style="text-align:left;margin:0;padding-left:1.1rem">'
                    + messages.map(function (m) {
                        return '<li>' + String(m).replace(/[&<>"']/g, function (c) {
                            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                        }) + '</li>';
                    }).join('')
                    + '</ul>',
            });
        };

        @if(session('success')) window.swalSuccess(@json(session('success'))); @endif
        @if(session('error')) window.swalError(@json(session('error'))); @endif
        @if(session('info')) window.swalInfo(@json(session('info'))); @endif
        @if($errors->any()) window.swalValidation(@json($errors->all())); @endif
    })();
    </script>

    <script>
    /*
     | Umpan balik unggahan. Berkas naik ke Google Drive di dalam request — berkas 20 MB
     | berarti halaman menggantung beberapa detik tanpa tanda apa pun. Yang terjadi lalu:
     | orang mengira tombolnya tak tertekan, menekannya lagi, dan berkas yang sama
     | terunggah berganda.
     |
     | Satu penangan terdelegasi menutup keduanya untuk SELURUH form unggah di aplikasi —
     | ISBN, naskah, bab, pembayaran, laporan harian, profil — bukan cuma yang kebetulan
     | diingat saat menulis fitur baru. Sengaja di luar blok SweetAlert supaya tetap
     | berjalan meski pustaka itu gagal dimuat.
     */
    (function () {
        window.kunciTombolUnggah = function (form) {
            if (!form || form.enctype !== 'multipart/form-data') return;
            var tombol = form.querySelector('button[type="submit"], button:not([type])');
            if (!tombol || tombol.dataset.mengunggah) return;
            tombol.dataset.mengunggah = '1';
            tombol.dataset.teksAsli = tombol.innerHTML;
            tombol.disabled = true;
            tombol.innerHTML = 'Mengunggah…';
        };

        document.addEventListener('submit', function (e) {
            var form = e.target;

            // Form berkonfirmasi dikunci di jalur SweetAlert-nya sendiri, SESUDAH
            // orangnya menekan "Ya". Mengunci di sini juga akan mematikan tombolnya
            // untuk selamanya begitu ia menekan "Batal": submit-nya memang dibatalkan
            // (preventDefault), tapi halaman tidak dimuat ulang, jadi tak ada yang
            // pernah memulihkan tombolnya.
            if (form && form.matches && form.matches('[data-confirm]')
                && form.dataset.confirmed !== '1') {
                return;
            }

            window.kunciTombolUnggah(form);
        }, true);

        // Tombol terkunci ikut tersimpan saat halaman masuk bfcache. Menekan Back
        // mengembalikan DOM apa adanya — tombolnya muncul kembali dalam keadaan mati
        // bertuliskan "Mengunggah…" padahal tak ada unggahan yang berjalan.
        window.addEventListener('pageshow', function (e) {
            if (! e.persisted) return;
            document.querySelectorAll('button[data-mengunggah]').forEach(function (t) {
                t.disabled = false;
                if (t.dataset.teksAsli) t.innerHTML = t.dataset.teksAsli;
                delete t.dataset.mengunggah;
            });
        });
    })();
    </script>

    <script>
    /*
     | Salinan batang gulir mendatar yang menempel di atas tiap .table-responsive.
     |
     | Dipasang satu kali untuk SELURUH aplikasi (56 view memakai .table-responsive)
     | supaya tak perlu diingat-ingat tiap kali ada tabel baru. Yang dipasang cuma
     | salinan kendali: elemen yang benar-benar menggulir tetap wadah aslinya, jadi
     | tak ada tata letak yang berubah dan wadah yang tak meluber tak kebagian apa pun.
     |
     | Berlaku juga untuk wadah gulir mendatar yang BUKAN tabel — papan zona Pelacakan
     | Naskah adalah alasan fitur ini diminta — lewat atribut data-gulir-mendatar.
     */
    (function () {
        function pasang(wadah) {
            if (wadah.dataset.gulirAtas) return;

            // Modal punya wadah gulirnya sendiri: "menempel 60px dari atas" di sana
            // berarti 60px dari puncak isi modal, bukan di bawah navbar — bar-nya
            // mengambang di tengah dialog. Tabel dalam modal juga pendek.
            if (wadah.closest && wadah.closest('.modal')) return;

            wadah.dataset.gulirAtas = '1';

            var proksi = document.createElement('div');
            proksi.className = 'gulir-tabel-atas';
            // Duplikat kendali, bukan isi: disembunyikan dari pembaca layar, dan
            // dikeluarkan dari urutan tab supaya tak jadi perhentian keyboard kosong
            // (wadah yang bisa digulir memang fokusabel di sebagian peramban).
            proksi.setAttribute('aria-hidden', 'true');
            proksi.setAttribute('tabindex', '-1');
            var isi = document.createElement('div');
            proksi.appendChild(isi);
            wadah.parentNode.insertBefore(proksi, wadah);

            // Menyetel scrollLeft memicu event scroll di sisi lawan; tanpa penjaga
            // ini keduanya saling mendorong dan gulirannya tersendat.
            var sedangMenyalin = false;
            function sambung(dari, ke) {
                dari.addEventListener('scroll', function () {
                    if (sedangMenyalin) return;
                    sedangMenyalin = true;
                    ke.scrollLeft = dari.scrollLeft;
                    sedangMenyalin = false;
                });
            }
            sambung(proksi, wadah);
            sambung(wadah, proksi);

            function selaraskan() {
                var meluber = wadah.scrollWidth > wadah.clientWidth + 1;
                proksi.style.display = meluber ? '' : 'none';
                if (meluber) {
                    isi.style.width = wadah.scrollWidth + 'px';
                    proksi.scrollLeft = wadah.scrollLeft;
                }
            }

            // Lebar tabel berubah di luar kendali kita: DataTables menggambar ulang,
            // kolom disembunyikan, jendela diubah ukurannya, tabel dalam collapse baru
            // punya lebar sesudah terbuka.
            if (window.ResizeObserver) {
                var pengamat = new ResizeObserver(selaraskan);
                pengamat.observe(wadah);
                if (wadah.firstElementChild) pengamat.observe(wadah.firstElementChild);
            }
            window.addEventListener('resize', selaraskan);

            wadah.__selaraskanGulir = selaraskan;
            selaraskan();
        }

        // .table-responsive dapat otomatis; apa pun yang menggulir mendatar tapi bukan
        // tabel (mis. papan zona Pelacakan Naskah) ikut dengan menambahkan atribut
        // data-gulir-mendatar pada wadah gulirnya.
        var SASARAN = '.table-responsive, [data-gulir-mendatar]';

        function sapu() {
            document.querySelectorAll(SASARAN).forEach(function (wadah) {
                pasang(wadah);
                if (wadah.__selaraskanGulir) wadah.__selaraskanGulir();
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', sapu);
        } else {
            sapu();
        }
        window.addEventListener('load', sapu);

        // Tabel yang tadinya tersembunyi baru punya lebar sesungguhnya setelah tampil.
        document.addEventListener('shown.bs.collapse', sapu);
        document.addEventListener('shown.bs.tab', sapu);
        document.addEventListener('shown.bs.modal', sapu);

        // DataTables menggambar ulang saat cari/urut/ganti halaman — lebar kolomnya
        // bisa ikut berubah. Peristiwanya jQuery, jadi hanya dipasang bila jQuery ada.
        if (window.jQuery) {
            window.jQuery(document).on('draw.dt responsive-resize.dt column-visibility.dt', sapu);
        }
    })();
    </script>
</body>

</html>
