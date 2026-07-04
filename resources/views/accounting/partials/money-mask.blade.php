@push('plugin-scripts')
<script src="{{ asset('assets/plugins/inputmask/jquery.inputmask.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    $('.money-mask').inputmask({
        alias: 'numeric', groupSeparator: '.', digits: 0, autoGroup: true,
        rightAlign: false, autoUnmask: true, removeMaskOnSubmit: true, allowMinus: false
    });
});
</script>
@endpush
