// Generates web/e2e/fixtures/media/test-landscape.mp4, the clip used by the long-video
// player E2E tests. Everything is drawn here; no external footage is involved.
//
//   Clip:   MP4 (moov at the start), H.264, 640×360 (16:9), 15 fps, 8 s, no audio.
//           A keyframe every second keeps seeking cheap.
//   Frames: background colour changes every second; large "S.F s" timestamp, a frame
//           counter and a progress bar make playback position and seeking visible.
//
// Requires macOS with the Swift toolchain (Xcode or Command Line Tools); uses only the
// system AVFoundation, CoreGraphics, CoreText and CoreVideo frameworks.
//
// Usage (from the repository root):
//   swift web/e2e/fixtures/media/generate-test-video.swift web/e2e/fixtures/media/test-landscape.mp4
//
// The picture content is identical on every run; the MP4 bytes can still differ slightly
// because the container records its creation time.
import AVFoundation
import CoreGraphics
import CoreText
import CoreVideo
import Foundation

let width = 640, height = 360, fps: Int32 = 15, seconds = 8
let totalFrames = Int(fps) * seconds

guard CommandLine.arguments.count == 2 else {
    FileHandle.standardError.write("usage: swift generate-test-video.swift <output.mp4>\n".data(using: .utf8)!)
    exit(2)
}
let output = URL(fileURLWithPath: CommandLine.arguments[1])
try? FileManager.default.removeItem(at: output)

let writer = try AVAssetWriter(outputURL: output, fileType: .mp4)
writer.shouldOptimizeForNetworkUse = true
let input = AVAssetWriterInput(mediaType: .video, outputSettings: [
    AVVideoCodecKey: AVVideoCodecType.h264,
    AVVideoWidthKey: width,
    AVVideoHeightKey: height,
    AVVideoCompressionPropertiesKey: [
        AVVideoAverageBitRateKey: 150_000,
        AVVideoMaxKeyFrameIntervalKey: Int(fps),
        AVVideoProfileLevelKey: AVVideoProfileLevelH264MainAutoLevel,
        AVVideoAllowFrameReorderingKey: false,
    ],
])
input.expectsMediaDataInRealTime = false
let adaptor = AVAssetWriterInputPixelBufferAdaptor(assetWriterInput: input, sourcePixelBufferAttributes: [
    kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
    kCVPixelBufferWidthKey as String: width,
    kCVPixelBufferHeightKey as String: height,
])
guard writer.canAdd(input) else { fatalError("cannot add video input") }
writer.add(input)
guard writer.startWriting() else { fatalError("cannot start writing: \(String(describing: writer.error))") }
writer.startSession(atSourceTime: .zero)

let palette: [(CGFloat, CGFloat, CGFloat)] = [
    (0.16, 0.36, 0.70), (0.13, 0.55, 0.38), (0.70, 0.36, 0.13), (0.55, 0.20, 0.55),
    (0.20, 0.50, 0.60), (0.62, 0.22, 0.25), (0.35, 0.45, 0.15), (0.30, 0.30, 0.34),
]

func drawText(_ text: String, size: CGFloat, centerX: CGFloat, baselineY: CGFloat, in ctx: CGContext) {
    let font = CTFontCreateWithName("Helvetica-Bold" as CFString, size, nil)
    let attributes: [NSAttributedString.Key: Any] = [
        NSAttributedString.Key(kCTFontAttributeName as String): font,
        NSAttributedString.Key(kCTForegroundColorAttributeName as String): CGColor(red: 1, green: 1, blue: 1, alpha: 1),
    ]
    let line = CTLineCreateWithAttributedString(NSAttributedString(string: text, attributes: attributes))
    let bounds = CTLineGetBoundsWithOptions(line, .useGlyphPathBounds)
    ctx.textPosition = CGPoint(x: centerX - bounds.width / 2 - bounds.minX, y: baselineY)
    CTLineDraw(line, ctx)
}

func makeFrame(_ index: Int) -> CVPixelBuffer {
    var buffer: CVPixelBuffer?
    guard let pool = adaptor.pixelBufferPool,
          CVPixelBufferPoolCreatePixelBuffer(nil, pool, &buffer) == kCVReturnSuccess, let frame = buffer
    else { fatalError("no pixel buffer") }
    CVPixelBufferLockBaseAddress(frame, [])
    defer { CVPixelBufferUnlockBaseAddress(frame, []) }
    guard let ctx = CGContext(
        data: CVPixelBufferGetBaseAddress(frame), width: width, height: height, bitsPerComponent: 8,
        bytesPerRow: CVPixelBufferGetBytesPerRow(frame), space: CGColorSpaceCreateDeviceRGB(),
        bitmapInfo: CGImageAlphaInfo.premultipliedFirst.rawValue | CGBitmapInfo.byteOrder32Little.rawValue
    ) else { fatalError("no context") }

    let second = index / Int(fps)
    let (r, g, b) = palette[second % palette.count]
    ctx.setFillColor(CGColor(red: r, green: g, blue: b, alpha: 1))
    ctx.fill(CGRect(x: 0, y: 0, width: width, height: height))

    let time = Double(index) / Double(fps)
    drawText(String(format: "%.1f s", time), size: 120, centerX: CGFloat(width) / 2, baselineY: 150, in: ctx)
    drawText("frame \(index + 1) / \(totalFrames)", size: 26, centerX: CGFloat(width) / 2, baselineY: 92, in: ctx)

    // Progress bar along the bottom edge.
    ctx.setFillColor(CGColor(red: 1, green: 1, blue: 1, alpha: 0.35))
    ctx.fill(CGRect(x: 40, y: 40, width: width - 80, height: 10))
    ctx.setFillColor(CGColor(red: 1, green: 1, blue: 1, alpha: 1))
    ctx.fill(CGRect(x: 40, y: 40, width: CGFloat(width - 80) * CGFloat(index + 1) / CGFloat(totalFrames), height: 10))
    return frame
}

for index in 0..<totalFrames {
    while !input.isReadyForMoreMediaData { usleep(2_000) }
    let time = CMTime(value: CMTimeValue(index), timescale: fps)
    guard adaptor.append(makeFrame(index), withPresentationTime: time) else {
        fatalError("append failed at frame \(index): \(String(describing: writer.error))")
    }
}
input.markAsFinished()
writer.endSession(atSourceTime: CMTime(value: CMTimeValue(totalFrames), timescale: fps))

let done = DispatchSemaphore(value: 0)
writer.finishWriting { done.signal() }
done.wait()
guard writer.status == .completed else { fatalError("writing failed: \(String(describing: writer.error))") }
print("wrote \(output.path) (\(width)x\(height), \(seconds) s, \(fps) fps, H.264)")
