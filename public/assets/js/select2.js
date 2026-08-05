// npm package: select2
// github link: https://github.com/select2/select2

$(function () {
    "use strict";

    if ($(".js-example-basic-single").length) {
        $(".js-example-basic-single").select2();
    }
    if ($(".js-example-basic-multiple").length) {
        $(".js-example-basic-multiple").select2();
    }
    // WAJIB "select.select2", BUKAN ".select2" polos.
    //
    // Select2 membungkus tiap elemen yang di-init dengan
    // <span class="select2 select2-container ...">. Selector ".select2" polos karena
    // itu ikut menangkap pembungkus buatannya SENDIRI, lalu mencoba meng-init sebuah
    // <span> sebagai dropdown — hasilnya widget rusak dengan daftar kosong.
    //
    // Ini menggigit di halaman yang meng-init Select2-nya sendiri lebih dulu: script
    // dari partial (mis. orders/partials/title-select) di-push ke custom-scripts dari
    // dalam @section('content'), sehingga jalan SEBELUM berkas ini.
    //
    // Semua pemakaian class select2 di resources/views/ ada di elemen <select>,
    // jadi mempersempit selector tidak menghilangkan satu pun dropdown.
    if ($("select.select2").length) {
        $("select.select2").select2();
    }
});
