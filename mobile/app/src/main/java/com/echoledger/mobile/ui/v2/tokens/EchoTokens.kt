// Generated from tokens/ by `npm run build` in tokens/. Do not edit: change the JSON there instead.
package com.echoledger.mobile.ui.v2.tokens

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** EchoLedger's design values for Compose. Brand and status colours stay fixed under dynamic colour. */
object EchoTokens {
    object Light {
        val bgCanvas = Color(0xFFFAFAF9)
        val bgSurface = Color(0xFFFFFFFF)
        val bgSubtle = Color(0xFFF4F4F2)
        val bgOverlay = Color(0xFFFFFFFF)
        val borderDefault = Color(0xFFE4E4E0)
        val borderStrong = Color(0xFFB8B8B2)
        val textPrimary = Color(0xFF1A1A1A)
        val textSecondary = Color(0xFF5C5C58)
        val textOnAccent = Color(0xFFFFFFFF)
        val textDisabled = Color(0xFFB8B8B2)
        val accentDefault = Color(0xFF0F6E6A)
        val accentHover = Color(0xFF0B5754)
        val accentSoft = Color(0xFFE6F2F1)
        val accentText = Color(0xFF0F6E6A)
        val accentFocus = Color(0xFF138781)
        val recordDefault = Color(0xFFD92D20)
        val recordSoft = Color(0xFFFEF3F2)
        val successDefault = Color(0xFF0E7654)
        val successSoft = Color(0xFFECFDF3)
        val warningDefault = Color(0xFFB54708)
        val warningSoft = Color(0xFFFFFAEB)
        val dangerDefault = Color(0xFFB42318)
        val dangerSoft = Color(0xFFFEF3F2)
    }

    object Dark {
        val bgCanvas = Color(0xFF111113)
        val bgSurface = Color(0xFF18181B)
        val bgSubtle = Color(0xFF1F1F23)
        val bgOverlay = Color(0xFF1F1F23)
        val borderDefault = Color(0xFF2C2C31)
        val borderStrong = Color(0xFF6E6E73)
        val textPrimary = Color(0xFFEDEDED)
        val textSecondary = Color(0xFFA1A1A6)
        val textOnAccent = Color(0xFF0B1F1E)
        val textDisabled = Color(0xFF6E6E73)
        val accentDefault = Color(0xFF3FB8AF)
        val accentHover = Color(0xFFCCE5E3)
        val accentSoft = Color(0xFF0B1F1E)
        val accentText = Color(0xFF3FB8AF)
        val accentFocus = Color(0xFF3FB8AF)
        val recordDefault = Color(0xFFF97066)
        val recordSoft = Color(0xFF2A0E0B)
        val successDefault = Color(0xFF4ADE80)
        val successSoft = Color(0xFF0A2117)
        val warningDefault = Color(0xFFFDB022)
        val warningSoft = Color(0xFF2B1A05)
        val dangerDefault = Color(0xFFF97066)
        val dangerSoft = Color(0xFF2A0E0B)
    }

    object Space {
        val s0 = 0.dp
        val s2 = 2.dp
        val s4 = 4.dp
        val s8 = 8.dp
        val s12 = 12.dp
        val s16 = 16.dp
        val s20 = 20.dp
        val s24 = 24.dp
        val s32 = 32.dp
        val s40 = 40.dp
        val s48 = 48.dp
        val s64 = 64.dp
    }

    object Radius {
        val sm = 4.dp
        val md = 8.dp
        val lg = 12.dp
        val sheet = 16.dp
        val full = 999.dp
    }

    object Layout {
        val pagePaddingDesktop = 24.dp
        val pagePaddingMobile = 16.dp
        val rowComfortable = 56.dp
        val rowCompact = 44.dp
        val rowMobile = 64.dp
        val controlHeight = 36.dp
        val controlHeightMobile = 48.dp
        val controlTouchMin = 48.dp
    }

    object Component {
        val buttonRadius = 4.dp
        val buttonHeight = 36.dp
        val buttonPaddingX = 12.dp
        val fieldRadius = 4.dp
        val fieldHeight = 36.dp
        val cardRadius = 8.dp
        val menuRadius = 8.dp
        val dialogRadius = 12.dp
        val sheetRadius = 16.dp
        val chipRadius = 999.dp
        val listRowHeight = 56.dp
        val listRowHeightCompact = 44.dp
        val listRowHeightMobile = 64.dp
    }

    object Motion {
        const val fastMs = 100
        const val baseMs = 200
        const val slowMs = 300
        const val pulseMs = 1500
    }

    object Type {
        val display = TextStyle(fontSize = 32.sp, lineHeight = 40.sp, fontWeight = FontWeight(600))
        val titleL = TextStyle(fontSize = 24.sp, lineHeight = 32.sp, fontWeight = FontWeight(600))
        val titleM = TextStyle(fontSize = 20.sp, lineHeight = 28.sp, fontWeight = FontWeight(600))
        val titleS = TextStyle(fontSize = 16.sp, lineHeight = 24.sp, fontWeight = FontWeight(600))
        val body = TextStyle(fontSize = 14.sp, lineHeight = 20.sp, fontWeight = FontWeight(400))
        val bodyStrong = TextStyle(fontSize = 14.sp, lineHeight = 20.sp, fontWeight = FontWeight(600))
        val caption = TextStyle(fontSize = 12.sp, lineHeight = 16.sp, fontWeight = FontWeight(400))
        val mono = TextStyle(fontSize = 13.sp, lineHeight = 20.sp, fontWeight = FontWeight(400))
        val urduBody = TextStyle(fontSize = 16.sp, lineHeight = 30.sp, fontWeight = FontWeight(400))
        val arabicBody = TextStyle(fontSize = 15.sp, lineHeight = 26.sp, fontWeight = FontWeight(400))
    }
}
