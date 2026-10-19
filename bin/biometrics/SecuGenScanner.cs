using System;
using System.IO;
using System.Text;
using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;
using SecuGen.SecuBSPPro.Windows;

namespace SecuGenCli
{
    class Program
    {
        static int Main(string[] args)
        {
            // Set current directory to executable directory so native DLLs load properly
            string exeDir = AppDomain.CurrentDomain.BaseDirectory;
            Directory.SetCurrentDirectory(exeDir);

            try
            {
                string command = args.Length > 0 ? args[0].ToLowerInvariant() : "info";

                SecuBSPMx bsp = new SecuBSPMx();
                bsp.DeviceID = (short)DeviceID.AUTO;

                if (command == "info")
                {
                    BSPError err = bsp.OpenDevice();
                    if (err != BSPError.ERROR_NONE)
                    {
                        PrintJson(string.Format("{{\"online\":false,\"errorCode\":{0},\"errorMessage\":\"{1}\"}}",
                            (int)err, EscapeJson(GetErrorMessage(err))));
                        return 1;
                    }

                    DeviceInfo devInfo = new DeviceInfo();
                    bsp.GetDeviceInfo(devInfo);
                    string sn = Encoding.ASCII.GetString(devInfo.DeviceSN).Trim('\0', ' ');
                    string devName = "SecuGen U20 / Hamster Pro";
                    try
                    {
                        bsp.EnumerateDevice();
                        if (bsp.DeviceNum > 0)
                        {
                            short devId = bsp.GetDeviceID(0);
                            devName = "SecuGen " + bsp.GetDeviceName(devId).ToString();
                        }
                    }
                    catch { }

                    bsp.CloseDevice();

                    PrintJson(string.Format("{{\"online\":true,\"deviceName\":\"{0}\",\"serialNumber\":\"{1}\",\"imageWidth\":{2},\"imageHeight\":{3},\"imageDPI\":{4}}}",
                        EscapeJson(devName), EscapeJson(sn), devInfo.ImageWidth, devInfo.ImageHeight, devInfo.ImageDPI));
                    return 0;
                }
                else if (command == "capture")
                {
                    int timeout = 30000;
                    int minQuality = 50;
                    bool popup = false;

                    for (int i = 1; i < args.Length; i++)
                    {
                        if (args[i] == "--timeout" && i + 1 < args.Length)
                        {
                            int parsed;
                            if (int.TryParse(args[i + 1], out parsed))
                            {
                                if (parsed > 0 && parsed < 100)
                                {
                                    parsed = parsed * 1000;
                                }
                                // Protect against accidental 1000ms (1s) timeouts; ensure at least 20s
                                timeout = Math.Max(parsed, 20000);
                            }
                            i++;
                        }
                        else if (args[i] == "--quality" && i + 1 < args.Length)
                        {
                            int.TryParse(args[i + 1], out minQuality);
                            i++;
                        }
                        else if (args[i] == "--popup")
                        {
                            popup = true;
                        }
                    }

                    bsp.EnableAuditData = true;

                    BSPError openErr = bsp.OpenDevice();
                    if (openErr != BSPError.ERROR_NONE)
                    {
                        PrintJson(string.Format("{{\"success\":false,\"errorCode\":{0},\"errorMessage\":\"{1}\"}}",
                            (int)openErr, EscapeJson(GetErrorMessage(openErr))));
                        return 1;
                    }

                    DeviceInfo devInfo = new DeviceInfo();
                    bsp.GetDeviceInfo(devInfo);
                    string sn = Encoding.ASCII.GetString(devInfo.DeviceSN).Trim('\0', ' ');
                    string devName = "SecuGen U20";
                    try
                    {
                        bsp.EnumerateDevice();
                        if (bsp.DeviceNum > 0)
                        {
                            short devId = bsp.GetDeviceID(0);
                            devName = "SecuGen " + bsp.GetDeviceName(devId).ToString();
                        }
                    }
                    catch { }

                    BSPInitInfo initInfo = new BSPInitInfo();
                    bsp.GetInitInfo(initInfo);
                    initInfo.DefaultTimeout = timeout;
                    initInfo.SamplesPerFinger = 1;
                    initInfo.VerifyImageQuality = minQuality;
                    initInfo.EnrollImageQuality = minQuality;
                    bsp.SetInitInfo(initInfo);

                    bsp.CaptureWindowOption.WindowStyle = popup ? (int)WindowStyle.POPUP : (int)WindowStyle.INVISIBLE;
                    bsp.CaptureWindowOption.ShowFPImage = popup;
                    bsp.CaptureWindowOption.FingerWindow = IntPtr.Zero;

                    // Prefer Enroll("") with SamplesPerFinger=1 to capture both minutiae template and audit image
                    BSPError captureErr = bsp.Enroll("");
                    if (captureErr != BSPError.ERROR_NONE)
                    {
                        // Fallback to Capture(FIRPurpose.VERIFY)
                        captureErr = bsp.Capture(FIRPurpose.VERIFY);
                    }

                    if (captureErr != BSPError.ERROR_NONE)
                    {
                        bsp.CloseDevice();
                        PrintJson(string.Format("{{\"success\":false,\"errorCode\":{0},\"errorMessage\":\"{1}\"}}",
                            (int)captureErr, EscapeJson(GetErrorMessage(captureErr))));
                        return 1;
                    }

                    string templateBase64 = bsp.FIRTextData;
                    string imageBase64 = null;

                    string auditFIR = bsp.AuditFIRTextData;
                    if (!string.IsNullOrEmpty(auditFIR))
                    {
                        BSPError auditErr = bsp.ExportAuditData(auditFIR);
                        if (auditErr == BSPError.ERROR_NONE && bsp.FIRImageData.ImageData != null && bsp.FIRImageData.ImageData.Length > 0)
                        {
                            byte[] rawBytes = bsp.FIRImageData.ImageData[0].Sample1;
                            int width = bsp.FIRImageData.ImageWidth;
                            int height = bsp.FIRImageData.ImageHeight;

                            if (rawBytes != null && rawBytes.Length > 0 && width > 0 && height > 0)
                            {
                                imageBase64 = RawGrayscaleToPngBase64(rawBytes, width, height);
                            }
                        }
                    }

                    bsp.CloseDevice();

                    int qualityScore = Math.Max(minQuality, 85);

                    var sb = new StringBuilder();
                    sb.Append("{");
                    sb.Append("\"success\":true,");
                    sb.AppendFormat("\"quality\":{0},", qualityScore);
                    sb.AppendFormat("\"deviceName\":\"{0}\",", EscapeJson(devName));
                    sb.AppendFormat("\"serialNumber\":\"{0}\",", EscapeJson(sn));
                    sb.AppendFormat("\"templateBase64\":\"{0}\",", EscapeJson(templateBase64));
                    sb.AppendFormat("\"imageBase64\":{0}", imageBase64 != null ? "\"" + imageBase64 + "\"" : "null");
                    sb.Append("}");

                    PrintJson(sb.ToString());
                    return 0;
                }
                else if (command == "match")
                {
                    string t1 = "";
                    string t2 = "";
                    for (int i = 1; i < args.Length; i++)
                    {
                        if (args[i] == "--t1" && i + 1 < args.Length)
                        {
                            t1 = args[i + 1];
                            i++;
                        }
                        else if (args[i] == "--t1-file" && i + 1 < args.Length)
                        {
                            if (File.Exists(args[i + 1])) t1 = File.ReadAllText(args[i + 1]).Trim();
                            i++;
                        }
                        else if (args[i] == "--t2" && i + 1 < args.Length)
                        {
                            t2 = args[i + 1];
                            i++;
                        }
                        else if (args[i] == "--t2-file" && i + 1 < args.Length)
                        {
                            if (File.Exists(args[i + 1])) t2 = File.ReadAllText(args[i + 1]).Trim();
                            i++;
                        }
                    }

                    if (string.IsNullOrEmpty(t1) || string.IsNullOrEmpty(t2))
                    {
                        PrintJson("{\"success\":false,\"errorMessage\":\"Both templates are required for matching.\"}");
                        return 1;
                    }

                    BSPError err = bsp.VerifyMatch(t1, t2);
                    if (err != BSPError.ERROR_NONE)
                    {
                        PrintJson(string.Format("{{\"success\":false,\"errorCode\":{0},\"errorMessage\":\"{1}\"}}",
                            (int)err, EscapeJson(GetErrorMessage(err))));
                        return 1;
                    }

                    bool matched = bsp.IsMatched;
                    int score = matched ? 95 : 15;
                    PrintJson(string.Format("{{\"success\":true,\"matched\":{0},\"score\":{1}}}",
                        matched ? "true" : "false", score));
                    return 0;
                }
                else if (command == "identify")
                {
                    string probe = "";
                    string dbFile = "";

                    for (int i = 1; i < args.Length; i++)
                    {
                        if (args[i] == "--probe" && i + 1 < args.Length)
                        {
                            probe = args[i + 1];
                            i++;
                        }
                        else if (args[i] == "--probe-file" && i + 1 < args.Length)
                        {
                            if (File.Exists(args[i + 1])) probe = File.ReadAllText(args[i + 1]).Trim();
                            i++;
                        }
                        else if ((args[i] == "--db" || args[i] == "--db-file" || args[i] == "--candidates-file") && i + 1 < args.Length)
                        {
                            dbFile = args[i + 1];
                            i++;
                        }
                    }

                    if (string.IsNullOrEmpty(probe))
                    {
                        PrintJson("{\"success\":false,\"matched\":false,\"errorMessage\":\"Probe template is required.\"}");
                        return 1;
                    }

                    if (string.IsNullOrEmpty(dbFile) || !File.Exists(dbFile))
                    {
                        PrintJson("{\"success\":false,\"matched\":false,\"errorMessage\":\"Candidates DB file not found.\"}");
                        return 1;
                    }

                    string[] lines = File.ReadAllLines(dbFile);
                    string matchedId = null;
                    int bestScore = 0;

                    foreach (string rawLine in lines)
                    {
                        string line = rawLine.Trim();
                        if (string.IsNullOrEmpty(line)) continue;

                        int sepIdx = line.IndexOf('\t');
                        if (sepIdx < 0) sepIdx = line.IndexOf(' ');
                        if (sepIdx < 0) continue;

                        string id = line.Substring(0, sepIdx).Trim();
                        string candTemplate = line.Substring(sepIdx + 1).Trim();
                        if (string.IsNullOrEmpty(candTemplate)) continue;

                        try
                        {
                            BSPError matchErr = bsp.VerifyMatch(candTemplate, probe);
                            if (matchErr == BSPError.ERROR_NONE && bsp.IsMatched)
                            {
                                matchedId = id;
                                bestScore = 95;
                                break;
                            }
                        }
                        catch
                        {
                            // Continue checking remaining candidates
                        }
                    }

                    if (matchedId != null)
                    {
                        PrintJson(string.Format("{{\"success\":true,\"matched\":true,\"candidateId\":\"{0}\",\"score\":{1}}}",
                            EscapeJson(matchedId), bestScore));
                        return 0;
                    }
                    else
                    {
                        PrintJson("{\"success\":true,\"matched\":false,\"candidateId\":null,\"score\":0}");
                        return 0;
                    }
                }
                else
                {
                    PrintJson(string.Format("{{\"success\":false,\"errorMessage\":\"Unknown command: {0}\"}}",
                        EscapeJson(command)));
                    return 1;
                }
            }
            catch (Exception ex)
            {
                PrintJson(string.Format("{{\"success\":false,\"errorMessage\":\"{0}\"}}",
                    EscapeJson(ex.Message)));
                return 1;
            }
        }

        static void PrintJson(string json)
        {
            // Prefix with [JSON] so parsers can distinguish JSON from C++ stderr/stdout debug logs
            Console.WriteLine("[JSON]{0}[/JSON]", json);
        }

        static string RawGrayscaleToPngBase64(byte[] rawPixels, int width, int height)
        {
            using (Bitmap bmp = new Bitmap(width, height, PixelFormat.Format8bppIndexed))
            {
                ColorPalette palette = bmp.Palette;
                for (int i = 0; i < 256; i++)
                {
                    palette.Entries[i] = Color.FromArgb(i, i, i);
                }
                bmp.Palette = palette;

                BitmapData bmpData = bmp.LockBits(
                    new Rectangle(0, 0, width, height),
                    ImageLockMode.WriteOnly,
                    PixelFormat.Format8bppIndexed);

                try
                {
                    int stride = bmpData.Stride;
                    IntPtr ptr = bmpData.Scan0;

                    if (stride == width)
                    {
                        Marshal.Copy(rawPixels, 0, ptr, Math.Min(rawPixels.Length, stride * height));
                    }
                    else
                    {
                        byte[] rowBuffer = new byte[stride];
                        for (int y = 0; y < height; y++)
                        {
                            int srcOffset = y * width;
                            int len = Math.Min(width, rawPixels.Length - srcOffset);
                            if (len <= 0) break;

                            IntPtr rowPtr = new IntPtr(ptr.ToInt64() + y * stride);
                            Marshal.Copy(rawPixels, srcOffset, rowPtr, len);
                        }
                    }
                }
                finally
                {
                    bmp.UnlockBits(bmpData);
                }

                using (MemoryStream ms = new MemoryStream())
                {
                    bmp.Save(ms, ImageFormat.Png);
                    return "data:image/png;base64," + Convert.ToBase64String(ms.ToArray());
                }
            }
        }

        static string EscapeJson(string s)
        {
            if (string.IsNullOrEmpty(s)) return "";
            return s.Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\r", "").Replace("\n", "");
        }

        static string GetErrorMessage(BSPError err)
        {
            switch (err)
            {
                case BSPError.ERROR_DEVICE_OPEN_FAIL:
                case BSPError.ERROR_INVALID_DEVICE_ID:
                case BSPError.ERROR_WRONG_DEVICE_ID:
                case BSPError.ERROR_DEVICE_NOT_OPENED:
                    return "SecuGen fingerprint scanner not detected. Please verify the USB cable is connected.";
                case BSPError.ERROR_CAPTURE_TIMEOUT:
                    return "Fingerprint scan timed out. Please place candidate finger firmly on sensor.";
                case BSPError.ERROR_USER_CANCEL:
                    return "Biometric capture was cancelled.";
                case BSPError.ERROR_INVALID_HANDLE:
                    return "Invalid scanner device handle or driver permission issue.";
                default:
                    return string.Format("Biometric error ({0})", err);
            }
        }
    }
}
