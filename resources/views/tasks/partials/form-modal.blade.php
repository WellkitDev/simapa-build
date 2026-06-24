<div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="taskForm" method="POST" action="{{ route('task.store') }}">
        @csrf
        <input type="hidden" name="_method" id="taskMethod" value="POST">
        <input type="hidden" name="status" id="taskStatus" value="todo">
        <div class="modal-header">
          <h5 class="modal-title" id="taskModalTitle">Tugas Baru</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Judul</label>
            <input type="text" name="title" id="taskTitle" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Deskripsi</label>
            <textarea name="description" id="taskDesc" class="form-control" rows="2"></textarea></div>
          <div class="row">
            <div class="col-6 mb-2"><label class="form-label">Prioritas</label>
              <select name="priority" id="taskPriority" class="form-select">
                <option value="low">Rendah</option>
                <option value="normal" selected>Normal</option>
                <option value="high">Tinggi</option>
              </select></div>
            <div class="col-6 mb-2"><label class="form-label">Tenggat</label>
              <input type="text" name="due_date" id="taskDue" class="form-control" placeholder="Pilih tanggal"></div>
          </div>
          @if($isManager ?? false)
          <div class="mb-2"><label class="form-label">Tugaskan ke</label>
            <select name="assignee" id="taskAssignee" class="form-select select2-assignee">
              <option value="{{ auth()->id() }}">Saya sendiri</option>
              @foreach(($assignees ?? collect()) as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
              @endforeach
            </select></div>
          @endif
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet">
@if($isManager ?? false)<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet">@endif
@endpush
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
@if($isManager ?? false)<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>@endif
@endpush
@push('custom-scripts')
<script>
(function () {
    const modalEl = document.getElementById('taskModal');
    const modal = (modalEl && window.bootstrap) ? new bootstrap.Modal(modalEl) : null;
    const form = document.getElementById('taskForm');
    const fp = window.flatpickr ? flatpickr('#taskDue', { dateFormat: 'Y-m-d' }) : null;
    @if($isManager ?? false)
    if (window.jQuery && jQuery.fn.select2) { jQuery('.select2-assignee').select2({ dropdownParent: jQuery(modalEl), width: '100%' }); }
    @endif

    window.openTaskModal = function (data) {
        data = data || {};
        document.getElementById('taskMethod').value = data.id ? 'PUT' : 'POST';
        document.getElementById('taskStatus').value = data.status || 'todo';
        form.setAttribute('action', data.id ? ("{{ url('tasks') }}/" + data.id) : "{{ route('task.store') }}");
        document.getElementById('taskModalTitle').textContent = data.id ? 'Edit Tugas' : 'Tugas Baru';
        document.getElementById('taskTitle').value = data.title || '';
        document.getElementById('taskDesc').value = data.description || '';
        document.getElementById('taskPriority').value = data.priority || 'normal';
        if (fp) { data.due ? fp.setDate(data.due) : fp.clear(); }
        @if($isManager ?? false)
        if (window.jQuery) jQuery('.select2-assignee').val(String(data.assignee || "{{ auth()->id() }}")).trigger('change');
        @endif
        if (modal) modal.show();
    };
})();
</script>
@endpush
