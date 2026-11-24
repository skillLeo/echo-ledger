package com.echoledger.mobile.testsupport

import android.app.Application
import com.echoledger.mobile.core.CallTargets

/**
 * WhatsApp as if it were installed, with a flat stand-in of its mark (the desktop's SourceLogos paths: its bubble and
 * handset on its green), so a screen or a notification shows an app's own icon as it does on a phone that has the app.
 */
fun Application.installWhatsAppStandIn() {
    val pm = org.robolectric.Shadows.shadowOf(packageManager)
    pm.installPackage(
        android.content.pm.PackageInfo().apply {
            packageName = CallTargets.WHATSAPP
            applicationInfo = android.content.pm.ApplicationInfo().apply { packageName = CallTargets.WHATSAPP }
        }
    )
    val size = 96
    val mark = android.graphics.Bitmap.createBitmap(size, size, android.graphics.Bitmap.Config.ARGB_8888)
    android.graphics.Canvas(mark).apply {
        scale(size / 24f, size / 24f)
        val paint = android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG)
        paint.color = android.graphics.Color.parseColor("#25D366")
        drawCircle(12f, 12f, 12f, paint)
        paint.color = android.graphics.Color.WHITE
        paint.style = android.graphics.Paint.Style.STROKE
        paint.strokeWidth = 1.5f
        paint.strokeJoin = android.graphics.Paint.Join.ROUND
        drawPath(androidx.core.graphics.PathParser.createPathFromPathData(WHATSAPP_BUBBLE), paint)
        paint.style = android.graphics.Paint.Style.FILL
        drawPath(androidx.core.graphics.PathParser.createPathFromPathData(WHATSAPP_HANDSET), paint)
    }
    pm.setApplicationIcon(CallTargets.WHATSAPP, android.graphics.drawable.BitmapDrawable(resources, mark))
    com.echoledger.mobile.ui.design.AppIcons.forget()
}

/** WhatsApp's bubble and handset on a 24-unit tile (desktop/src/components/SourceLogos.tsx). */
const val WHATSAPP_BUBBLE = "M12 4.6a7.4 7.4 0 0 0-6.4 11.1L4.6 19.4l3.8-1a7.4 7.4 0 1 0 3.6-13.8z"
const val WHATSAPP_HANDSET = "M9.3 7.6c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.1.3 0 .5.4.7.9 1.3 1.5 1.8.5.4 1 .7 " +
    "1.6 1 .2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.6.8c.2.1.3.3.3.5 0 .5-.2 1-.6 1.3-.5.4-1.1.6-1.7.5-1.1-.2-2.2-.7-3.1-1.4" +
    "-1-.8-1.9-1.8-2.5-2.9-.4-.7-.5-1.5-.3-2.2.2-.4.4-.8.8-1.1.2-.2.4-.2.6-.2z"
