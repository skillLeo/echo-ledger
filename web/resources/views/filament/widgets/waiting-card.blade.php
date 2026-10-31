{{-- "Keep or discard?": the phone's Needs action card for calls waiting for a decision. --}}
<div class="el-waiting" data-waiting-decisions>
    @svg('heroicon-o-clock', 'el-waiting-icon', ['aria-hidden' => 'true'])
    <p class="el-waiting-title">Keep or discard?</p>
    <a href="{{ $reviewUrl }}" class="el-text-btn el-waiting-action">Review</a>
    <p class="el-waiting-line">{{ $line }}</p>
    <p class="el-waiting-hint">The phone or computer that recorded them decides Keep or Discard.</p>
</div>
