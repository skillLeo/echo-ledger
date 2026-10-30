package com.echoledger.mobile.overlay

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.DashPathEffect
import android.graphics.Paint
import android.graphics.RectF
import android.graphics.Typeface
import android.text.Layout
import android.text.SpannableStringBuilder
import android.text.Spanned
import android.text.StaticLayout
import android.text.TextDirectionHeuristics
import android.text.TextPaint
import android.text.TextUtils
import android.text.style.ForegroundColorSpan
import android.view.View
import androidx.core.content.res.ResourcesCompat
import com.echoledger.mobile.R
import com.echoledger.mobile.translate.BoxBackground
import com.echoledger.mobile.translate.TranslateLanguages

/**
 * The text of Live Translate (plan B2/B4), drawn by hand so the window stays cheap while a game runs: no layout passes
 * (the window has a fixed size; lines scroll inside it), one StaticLayout per text change (at most 10 a second), no
 * blur or shadows except the 1 dp outline of text drawn without a background.
 *
 * Box: a rounded panel (dark 72 % / light 85 % / none), Inter 500, the newest [lines] lines; words still changing at 60 %.
 * Subtitles: centred white text with a dark outline, optionally on a black 60 % box. An optional small line of what was
 * said sits above. Urdu uses Noto Nastaliq with extra line height; Arabic and Urdu run right to left.
 */
@SuppressLint("ViewConstructor")
class TranslateTextView(context: Context, private val subtitles: Boolean) : View(context) {
    private val density = resources.displayMetrics.density
    private val scaled = resources.displayMetrics.scaledDensity
    private val inter = runCatching { ResourcesCompat.getFont(context, R.font.inter) }.getOrNull()?.let { Typeface.create(it, 500, false) }
    private val nastaliq = runCatching { ResourcesCompat.getFont(context, R.font.noto_nastaliq_urdu) }.getOrNull()
    private val naskh = runCatching { ResourcesCompat.getFont(context, R.font.noto_naskh_arabic) }.getOrNull()

    private val fill = TextPaint(Paint.ANTI_ALIAS_FLAG)
    private val stroke = TextPaint(Paint.ANTI_ALIAS_FLAG).apply {
        style = Paint.Style.STROKE
        strokeJoin = Paint.Join.ROUND
    }
    private val small = TextPaint(Paint.ANTI_ALIAS_FLAG)
    private val panel = Paint(Paint.ANTI_ALIAS_FLAG)
    private val outline = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        style = Paint.Style.STROKE
        color = Color.WHITE
    }
    private val rect = RectF()

    private var textSp = 16
    private var lines = 2
    private var background = BoxBackground.DARK
    private var subtitleBox = false
    private var language: String? = null
    private var showOriginal = false

    private var finalText = ""
    private var partialText = ""
    private var original = ""
    private var originalLanguage: String? = null
    private var waiting = false
    private var notice: String? = null
    private var editing = false

    private var layoutFill: StaticLayout? = null
    private var layoutStroke: StaticLayout? = null
    private var dots = 0

    fun style(textSp: Int, lines: Int, background: BoxBackground, showOriginal: Boolean, language: String?, subtitleBox: Boolean = false) {
        this.textSp = textSp
        this.lines = lines
        this.background = background
        this.showOriginal = showOriginal
        this.language = language
        this.subtitleBox = subtitleBox
        rebuild()
    }

    /** New text (called at most 10 times a second by the overlay). [waiting]: someone speaks, no text yet. */
    fun content(finalText: String, partial: String, original: String, originalLanguage: String?, waiting: Boolean, notice: String?) {
        if (finalText == this.finalText && partial == partialText && original == this.original && waiting == this.waiting &&
            notice == this.notice
        ) {
            return
        }
        this.originalLanguage = originalLanguage
        this.finalText = finalText
        this.partialText = partial
        this.original = original
        this.notice = notice
        val startDots = waiting && !this.waiting
        this.waiting = waiting
        rebuild()
        if (startDots) postInvalidateDelayed(DOT_MS)
    }

    fun setEditing(on: Boolean) {
        editing = on
        invalidate()
    }

    /** The height that shows [lines] lines (and the original line) at the current size. */
    fun preferredHeight(): Int {
        val lineHeight = textSp * scaled * lineSpacing()
        val originalHeight = if (showOriginal) ORIGINAL_SP * scaled * 1.4f else 0f
        return (lines * lineHeight + originalHeight + 2 * padV()).toInt()
    }

    private fun lineSpacing(): Float = if (language == "ur") 1.7f else 1.3f

    private fun padH() = if (subtitles) 8 * density else 12 * density

    private fun padV() = if (subtitles) 4 * density else 10 * density

    private fun rebuild() {
        val width = (width - 2 * padH()).toInt()
        if (width <= 0) {
            layoutFill = null
            layoutStroke = null
            invalidate()
            return
        }
        val face = when (language) {
            "ur" -> nastaliq
            "ar", "fa" -> naskh
            else -> inter
        } ?: Typeface.DEFAULT
        val onLight = !subtitles && background == BoxBackground.LIGHT
        val textColor = if (onLight) Color.rgb(0x1C, 0x20, 0x24) else Color.WHITE
        fill.typeface = face
        fill.textSize = textSp * scaled
        fill.color = textColor
        stroke.typeface = face
        stroke.textSize = textSp * scaled
        stroke.strokeWidth = 2 * density
        stroke.color = Color.argb(220, 0, 0, 0)
        small.typeface = naskh?.takeIf { TranslateLanguages.rtl(originalLanguage) } ?: inter ?: Typeface.DEFAULT
        small.textSize = ORIGINAL_SP * scaled
        small.color = if (onLight) Color.argb(178, 0x1C, 0x20, 0x24) else Color.argb(178, 255, 255, 255)

        val shown = SpannableStringBuilder()
        notice?.let { shown.append(it) } ?: run {
            shown.append(finalText)
            if (partialText.isNotEmpty()) {
                val start = shown.length
                shown.append(partialText)
                shown.setSpan(ForegroundColorSpan(withAlpha(textColor, 0.6f)), start, shown.length, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
            }
        }
        val rtl = TranslateLanguages.rtl(language)
        val align = if (subtitles) Layout.Alignment.ALIGN_CENTER else Layout.Alignment.ALIGN_NORMAL
        fun build(paint: TextPaint) = StaticLayout.Builder.obtain(shown, 0, shown.length, paint, width)
            .setAlignment(align)
            .setTextDirection(if (rtl) TextDirectionHeuristics.RTL else TextDirectionHeuristics.FIRSTSTRONG_LTR)
            .setLineSpacing(0f, lineSpacing() * (textSp * scaled) / paint.fontSpacing.coerceAtLeast(1f))
            .setIncludePad(false)
            .build()
        layoutFill = build(fill)
        layoutStroke = if (outlined()) build(stroke) else null
        invalidate()
    }

    /** Text drawn without a panel needs the outline to read on any scene. */
    private fun outlined(): Boolean = if (subtitles) !subtitleBox else background == BoxBackground.NONE

    override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
        rebuild()
    }

    override fun onDraw(canvas: Canvas) {
        val w = width.toFloat()
        val h = height.toFloat()
        // The panel.
        val panelColor = when {
            subtitles && subtitleBox -> Color.argb(153, 0, 0, 0)
            subtitles -> Color.TRANSPARENT
            background == BoxBackground.DARK -> Color.argb(184, 0, 0, 0)
            background == BoxBackground.LIGHT -> Color.argb(217, 255, 255, 255)
            else -> Color.TRANSPARENT
        }
        val radius = if (subtitles) 6 * density else 14 * density
        if (panelColor != Color.TRANSPARENT) {
            panel.color = panelColor
            rect.set(0f, 0f, w, h)
            canvas.drawRoundRect(rect, radius, radius, panel)
        }
        if (editing) {
            outline.strokeWidth = 1.5f * density
            outline.pathEffect = DashPathEffect(floatArrayOf(6 * density, 4 * density), 0f)
            rect.set(outline.strokeWidth, outline.strokeWidth, w - outline.strokeWidth, h - outline.strokeWidth)
            canvas.drawRoundRect(rect, radius, radius, outline)
        }
        var top = padV()
        if (showOriginal && original.isNotBlank() && notice == null) {
            val line = TextUtils.ellipsize(original.takeLast(200), small, w - 2 * padH(), TextUtils.TruncateAt.START)
            val x = if (subtitles) (w - small.measureText(line, 0, line.length)) / 2 else padH()
            canvas.drawText(line, 0, line.length, x, top - small.ascent(), small)
            top += ORIGINAL_SP * scaled * 1.4f
        }
        val layout = layoutFill
        if (layout == null || layout.text.isEmpty()) {
            if (waiting) drawDots(canvas, top)
            return
        }
        // The newest lines only: the layout is shifted up so its last [lines] lines fill the space.
        val first = (layout.lineCount - lines).coerceAtLeast(0)
        val shift = layout.getLineTop(first).toFloat()
        val save = canvas.save()
        canvas.clipRect(0f, top, w, h - padV() + 2 * density)
        canvas.translate(padH(), top - shift)
        layoutStroke?.draw(canvas)
        layout.draw(canvas)
        canvas.restoreToCount(save)
    }

    /** "…" while someone speaks and no text has come yet. */
    private fun drawDots(canvas: Canvas, top: Float) {
        dots = (dots + 1) % 3
        val r = 3 * density
        val y = top + textSp * scaled * 0.6f
        val x0 = if (subtitles) width / 2f - 10 * density else padH() + r
        for (i in 0..2) {
            fill.alpha = if (i == dots) 255 else 110
            canvas.drawCircle(x0 + i * 10 * density, y, r, fill)
        }
        fill.alpha = 255
        if (waiting) postInvalidateDelayed(DOT_MS)
    }

    private fun withAlpha(color: Int, alpha: Float) =
        Color.argb((alpha * 255).toInt(), Color.red(color), Color.green(color), Color.blue(color))

    companion object {
        const val ORIGINAL_SP = 12
        private const val DOT_MS = 300L
    }
}
