package com.echoledger.mobile.tile

import android.os.Build
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import com.echoledger.mobile.R
import com.echoledger.mobile.core.ShareGuard

/**
 * Quick Settings tile "Hide EchoLedger windows" (since v1.8.1): before a screen share, one tap turns every floating
 * window into a grey dot; another tap (or a tap on the dot) shows them again. Works on every Android version. The state
 * lives in [ShareGuard], so each tap works the same whichever binding of this service SystemUI sends it to.
 *
 * SystemUI hands a tap to this service while the tile is listening (the Quick Settings panel is open) or still bound
 * (about 30 s after the panel closes). `adb shell cmd statusbar click-tile` with the panel closed after that is only
 * queued, not delivered (AOSP TileLifecycleManager), so a test by adb opens the panel first
 * (`cmd statusbar expand-settings`).
 */
class HideCardsTileService : TileService() {
    override fun onStartListening() = refresh(ShareGuard.hiddenByUser.value)

    override fun onClick() = refresh(ShareGuard.toggleHidden())

    private fun refresh(hidden: Boolean) {
        val tile = qsTile ?: return
        tile.state = if (hidden) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
        tile.label = getString(R.string.tile_hide_windows)
        if (Build.VERSION.SDK_INT >= 29) tile.subtitle = getString(if (hidden) R.string.tile_hidden else R.string.tile_shown)
        tile.updateTile()
    }
}
