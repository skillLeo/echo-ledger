package com.echoledger.mobile.tile

import android.app.Activity
import android.app.PendingIntent
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import com.echoledger.mobile.recording.RecordingService

/**
 * Quick Settings tile. A tile click is not a documented mic-FGS exemption on every release, so the tile opens a
 * translucent trampoline activity (visible → allowed to start the service). VERIFY ON DEVICE.
 */
class RecordTileService : TileService() {
    override fun onStartListening() {
        qsTile?.apply {
            state = if (RecordingService.isRecording) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
            label = if (RecordingService.isRecording) "Stop recording" else "Record"
            updateTile()
        }
    }

    @Suppress("DEPRECATION")
    override fun onClick() {
        val intent = Intent(this, TileTrampolineActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        if (Build.VERSION.SDK_INT >= 34) {
            startActivityAndCollapse(
                PendingIntent.getActivity(
                    this,
                    0,
                    intent,
                    PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
                )
            )
        } else {
            // Below API 34 only the Intent overload exists (the PendingIntent one was added in 34).
            @Suppress("DEPRECATION", "StartActivityAndCollapseDeprecated")
            startActivityAndCollapse(intent)
        }
    }
}

class TileTrampolineActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        startForegroundService(RecordingService.intent(this, RecordingService.ACTION_TOGGLE, from = "Quick Settings tile"))
        finish()
    }
}
