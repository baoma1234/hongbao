    <div class="slider-modal" id="sliderCaptchaModal" onclick="if(event.target===this)closeSliderCaptcha()">
        <div class="slider-box" onclick="event.stopPropagation()">
            <div class="slider-box-hd">
                <div class="slider-box-title" data-copy="slider_modal_title">安全验证</div>
                <button type="button" class="slider-box-refresh" id="sliderRefreshBtn" data-copy="slider_refresh_btn">重试</button>
            </div>
            <div class="slider-box-hint" id="sliderModalHint" data-copy="slider_modal_hint">请按住滑块，拖动到最右侧</div>
            <div class="slider-track" id="sliderTrack">
                <div class="slider-track-fill" id="sliderTrackFill"></div>
                <div class="slider-track-hint" id="sliderTrackHint" data-copy="slider_track_hint">拖动滑块到右侧 →</div>
                <div class="slider-thumb" id="sliderThumb" role="slider" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">›</div>
            </div>
            <div class="slider-status" id="sliderStatusText" aria-live="polite"></div>
        </div>
    </div>
