package com.echoledger.mobile.ui.design

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontVariation
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.R

private fun variable(id: Int, weight: Int) =
    Font(id, FontWeight(weight), variationSettings = FontVariation.Settings(FontVariation.weight(weight)))

/** Inter (bundled, OFL): every UI text, whatever font the phone uses. Sizes follow the phone's font size setting. */
val Inter = FontFamily(variable(R.font.inter, 400), variable(R.font.inter, 500), variable(R.font.inter, 600), variable(R.font.inter, 700))

/** Noto Nastaliq Urdu (bundled, OFL): Urdu transcript text. */
val UrduNastaliq = FontFamily(variable(R.font.noto_nastaliq_urdu, 400), variable(R.font.noto_nastaliq_urdu, 600))

/** Noto Naskh Arabic (bundled, OFL): Arabic transcript text. */
val ArabicNaskh = FontFamily(variable(R.font.noto_naskh_arabic, 400), variable(R.font.noto_naskh_arabic, 600))

/**
 * The type scale (docs/DESIGN_SYSTEM.md). At most three sizes on a screen. Times and durations add [tabular] so digits
 * line up and do not jump while a clock runs.
 */
class EchoType(private val family: FontFamily) {
    private fun style(size: Int, line: Int, weight: FontWeight, tracking: Double = 0.0) = TextStyle(
        fontFamily = family,
        fontWeight = weight,
        fontSize = size.sp,
        lineHeight = line.sp,
        letterSpacing = tracking.em
    )

    /** Onboarding only. */
    val display = style(28, 34, FontWeight.SemiBold, -0.01)
    val titleL = style(24, 30, FontWeight.SemiBold, -0.0125)
    val titleM = style(20, 26, FontWeight.SemiBold)
    val titleS = style(17, 22, FontWeight.SemiBold)

    /** Row titles. */
    val bodyL = style(16, 22, FontWeight.Medium)
    val body = style(16, 22, FontWeight.Normal)
    val bodyM = style(15, 22, FontWeight.Normal)

    /** Buttons. */
    val labelL = style(14, 20, FontWeight.SemiBold)

    /** Chips and meta lines. */
    val labelM = style(13, 18, FontWeight.Medium)
    val caption = style(12, 16, FontWeight.Normal)

    /** Bottom-bar labels. */
    val micro = style(11, 14, FontWeight.Medium)

    /** Transcript text; Urdu and Arabic read right to left from the right edge. */
    val transcript = style(17, 28, FontWeight.Normal)
    val transcriptUrdu = TextStyle(fontFamily = UrduNastaliq, fontSize = 19.sp, lineHeight = 38.sp, textDirection = TextDirection.Rtl)
    val transcriptArabic = TextStyle(fontFamily = ArabicNaskh, fontSize = 18.sp, lineHeight = 30.sp, textDirection = TextDirection.Rtl)

    /** Material 3 roles mapped onto the scale, so stock components pick it up. */
    fun material() = Typography(
        displaySmall = display,
        headlineLarge = display,
        headlineMedium = titleL,
        headlineSmall = titleL,
        titleLarge = titleM,
        titleMedium = titleS,
        titleSmall = bodyL,
        bodyLarge = body,
        bodyMedium = bodyM,
        bodySmall = caption,
        labelLarge = labelL,
        labelMedium = labelM,
        labelSmall = micro
    )

    companion object {
        val Bundled = EchoType(Inter)

        /** Appearance → Use phone font. */
        val Phone = EchoType(FontFamily.Default)
    }
}

/** Digits of equal width ("tnum"), for clocks, durations and times of day. */
fun TextStyle.tabular(): TextStyle = copy(fontFeatureSettings = "tnum")
