package com.echoledger.mobile.tile

import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import com.echoledger.mobile.core.ShareGuard

/**
 * Quick Settings tile "Hide EchoLedger cards" (since v1.8.1): before sharing the screen, one tap turns every EchoLedger
 * card into a plain dot; another tap (or a tap on the dot) shows them again. Works on every Android version.
 */
class HideCardsTileService : TileService() {
    override fun onStartListening() = refresh()

    override fun onClick() {
        ShareGuard.toggleHidden()
        refresh()
    }

    private fun refresh() {
        qsTile?.apply {
            val hidden = ShareGuard.hiddenByUser.value
            state = if (hidden) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
            label = getString(com.echoledger.mobile.R.string.tile_hide_cards)
            if (android.os.Build.VERSION.SDK_INT >= 29) subtitle = if (hidden) "Hidden" else "Shown"
            updateTile()
        }
    }
}
