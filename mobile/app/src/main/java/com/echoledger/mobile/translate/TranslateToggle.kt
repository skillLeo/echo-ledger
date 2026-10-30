package com.echoledger.mobile.translate

import android.app.Activity
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import androidx.core.net.toUri
import com.echoledger.mobile.EchoLedgerApp

/**
 * Starts or stops Game / Watch translate from a tile, an app shortcut (Samsung Routines, back tap) or the app. A
 * visible (translucent) activity, so Android lets it start the service; when the capture helper is not running it asks
 * for Android's own screen-audio consent first. Nothing else is shown; it finishes at once.
 */
class TranslateToggleActivity : Activity() {
    private var mode = TranslateMode.GAME
    private var pkg: String? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        mode = TranslateMode.of(intent.getStringExtra(LiveTranslateService.EXTRA_MODE)) ?: TranslateMode.GAME
        pkg = intent.getStringExtra(LiveTranslateService.EXTRA_PACKAGE)
        val forceStart = intent.getBooleanExtra(EXTRA_START_ONLY, false)
        if (LiveTranslateService.running.value == mode && !forceStart) {
            LiveTranslateService.stop(this)
            finish()
            return
        }
        if (!Settings.canDrawOverlays(this)) {
            // The box needs "Display over other apps": open that page for EchoLedger.
            runCatching {
                startActivity(
                    Intent(
                        Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                        "package:$packageName".toUri()
                    ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                )
            }
            finish()
            return
        }
        val helperRunning = (application as EchoLedgerApp).graph.helper.helper != null
        if (helperRunning) {
            startForegroundService(LiveTranslateService.startIntent(this, mode, pkg))
            finish()
        } else {
            val manager = getSystemService(MediaProjectionManager::class.java)
            @Suppress("DEPRECATION")
            startActivityForResult(manager.createScreenCaptureIntent(), REQUEST_PROJECTION)
        }
    }

    @Deprecated("Activity result API is not used in this one-shot trampoline")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        @Suppress("DEPRECATION")
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == REQUEST_PROJECTION && resultCode == RESULT_OK && data != null) {
            startForegroundService(LiveTranslateService.startIntent(this, mode, pkg, projection = data))
        }
        finish()
    }

    companion object {
        private const val REQUEST_PROJECTION = 7
        const val EXTRA_START_ONLY = "start_only"

        fun intent(context: Context, mode: TranslateMode, packageName: String? = null, startOnly: Boolean = false): Intent =
            Intent(context, TranslateToggleActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                .putExtra(LiveTranslateService.EXTRA_MODE, mode.key)
                .putExtra(EXTRA_START_ONLY, startOnly)
                .apply { packageName?.let { putExtra(LiveTranslateService.EXTRA_PACKAGE, it) } }
    }
}

/** Quick Settings tiles "Game translate" and "Watch translate" (plan B2). */
abstract class TranslateTile(private val mode: TranslateMode) : TileService() {
    override fun onStartListening() {
        qsTile?.apply {
            state = if (LiveTranslateService.running.value == mode) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
            label = mode.label
            updateTile()
        }
    }

    override fun onClick() {
        val intent = TranslateToggleActivity.intent(this, mode)
        if (Build.VERSION.SDK_INT >= 34) {
            startActivityAndCollapse(
                PendingIntent.getActivity(
                    this,
                    mode.ordinal,
                    intent,
                    PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
                )
            )
        } else {
            @Suppress("DEPRECATION", "StartActivityAndCollapseDeprecated")
            startActivityAndCollapse(intent)
        }
    }
}

class GameTranslateTile : TranslateTile(TranslateMode.GAME)

class WatchTranslateTile : TranslateTile(TranslateMode.WATCH)
