package com.echoledger.mobile.ui

import android.util.Size
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.CameraState
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.core.resolutionselector.ResolutionStrategy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.Observer
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.zxing.BinaryBitmap
import com.google.zxing.DecodeHintType
import com.google.zxing.PlanarYUVLuminanceSource
import com.google.zxing.common.HybridBinarizer
import com.google.zxing.qrcode.QRCodeReader
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong
import kotlinx.coroutines.delay

/**
 * Full-screen QR scanner for pairing: CameraX preview + on-device ZXing decoding. No Google services, and no frame
 * ever leaves the phone. Only a code that [accept]s is delivered, exactly once; other codes show a hint and scanning
 * continues. The caller requests the camera permission first.
 */
@Composable
fun QrScanner(accept: (String) -> Boolean, onResult: (String) -> Unit, onCancel: () -> Unit, onError: (String) -> Unit) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val latestAccept by rememberUpdatedState(accept)
    val latestOnResult by rememberUpdatedState(onResult)
    val latestOnError by rememberUpdatedState(onError)
    val previewView = remember { PreviewView(context).apply { scaleType = PreviewView.ScaleType.FILL_CENTER } }
    var hint by remember { mutableStateOf<String?>(null) }
    var inUseElsewhere by remember { mutableStateOf(false) }

    LaunchedEffect(hint) {
        if (hint != null) {
            delay(2_500)
            hint = null
        }
    }

    DisposableEffect(lifecycleOwner) {
        val analysisExecutor = Executors.newSingleThreadExecutor()
        val mainExecutor = ContextCompat.getMainExecutor(context)
        val delivered = AtomicBoolean(false)
        val lastHintAt = AtomicLong(0)
        var disposed = false
        var provider: ProcessCameraProvider? = null
        var camera: Camera? = null
        val stateObserver = Observer<CameraState> { state ->
            val error = state.error ?: return@Observer
            when (error.code) {
                CameraState.ERROR_CAMERA_IN_USE, CameraState.ERROR_MAX_CAMERAS_IN_USE -> inUseElsewhere = true

                else -> if (error.type == CameraState.ErrorType.CRITICAL) {
                    latestOnError("The camera could not be started. Enter the server address and code by hand instead.")
                }
            }
        }
        val future = ProcessCameraProvider.getInstance(context)
        future.addListener({
            if (disposed) return@addListener
            try {
                val cameraProvider = future.get().also { provider = it }
                val selector = listOf(CameraSelector.DEFAULT_BACK_CAMERA, CameraSelector.DEFAULT_FRONT_CAMERA)
                    .firstOrNull { runCatching { cameraProvider.hasCamera(it) }.getOrDefault(false) }
                    ?: cameraProvider.availableCameraInfos.firstOrNull()?.cameraSelector
                    ?: throw IllegalStateException("no camera")
                val preview = Preview.Builder().build().also { it.setSurfaceProvider(previewView.surfaceProvider) }
                // The pairing QR is dense (URL + code + six pins), so analyse frames at up to 1080p.
                val analysis = ImageAnalysis.Builder()
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                    .setResolutionSelector(
                        ResolutionSelector.Builder()
                            .setResolutionStrategy(
                                ResolutionStrategy(Size(1920, 1080), ResolutionStrategy.FALLBACK_RULE_CLOSEST_LOWER_THEN_HIGHER)
                            )
                            .build()
                    )
                    .build()
                analysis.setAnalyzer(
                    analysisExecutor,
                    QrAnalyzer { text ->
                        if (!latestAccept(text)) {
                            val now = System.currentTimeMillis()
                            if (now - lastHintAt.get() > 2_000) {
                                lastHintAt.set(now)
                                mainExecutor.execute { if (!disposed) hint = "That isn't an EchoLedger pairing code" }
                            }
                        } else if (delivered.compareAndSet(false, true)) {
                            mainExecutor.execute { if (!disposed) latestOnResult(text) }
                        }
                    }
                )
                cameraProvider.unbindAll()
                camera = cameraProvider.bindToLifecycle(lifecycleOwner, selector, preview, analysis).also {
                    it.cameraInfo.cameraState.observe(lifecycleOwner, stateObserver)
                }
            } catch (e: Exception) {
                latestOnError("The camera could not be started. Enter the server address and code by hand instead.")
            }
        }, mainExecutor)
        onDispose {
            disposed = true
            camera?.cameraInfo?.cameraState?.removeObserver(stateObserver)
            provider?.unbindAll()
            analysisExecutor.shutdown()
        }
    }

    Box(Modifier.fillMaxSize().background(Color.Black)) {
        AndroidView(factory = { previewView }, modifier = Modifier.fillMaxSize())
        Box(Modifier.align(Alignment.Center).size(260.dp).border(3.dp, Brand.Cyan, RoundedCornerShape(20.dp)))
        Column(
            Modifier.align(Alignment.TopCenter).padding(top = 72.dp, start = 24.dp, end = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            Text("Scan the pairing QR code", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 20.sp)
            Text(
                "In your EchoLedger admin open Devices → Pair a device and point the camera at the code.",
                color = Color.White.copy(alpha = 0.8f),
                textAlign = TextAlign.Center
            )
            when {
                inUseElsewhere -> Text(
                    "The camera is in use by another app. Close it and try again.",
                    color = Brand.Danger,
                    textAlign = TextAlign.Center
                )

                hint != null -> Text(hint!!, color = Brand.Cyan, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center)
            }
        }
        OutlinedButton(
            onClick = onCancel,
            border = BorderStroke(1.dp, Color.White.copy(alpha = 0.6f)),
            modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 48.dp)
        ) { Text("Cancel", color = Color.White) }
    }
}

/** Decodes QR codes from the luminance (Y) plane of each camera frame. QR detection is rotation-independent. */
private class QrAnalyzer(private val onFound: (String) -> Unit) : ImageAnalysis.Analyzer {
    private val reader = QRCodeReader()
    private val hints = mapOf<DecodeHintType, Any>(DecodeHintType.TRY_HARDER to true)

    override fun analyze(image: ImageProxy) {
        try {
            val plane = image.planes[0]
            val buffer = plane.buffer
            // Pad to rowStride × height: the last row of the Y plane may be shorter than the stride.
            val data = ByteArray(plane.rowStride * image.height)
            buffer.get(data, 0, minOf(buffer.remaining(), data.size))
            val source = PlanarYUVLuminanceSource(data, plane.rowStride, image.height, 0, 0, image.width, image.height, false)
            onFound(reader.decode(BinaryBitmap(HybridBinarizer(source)), hints).text)
        } catch (e: Exception) {
            // No readable QR code in this frame, or an unexpected decoder failure: skip the frame and keep scanning.
        } finally {
            reader.reset()
            image.close()
        }
    }
}
