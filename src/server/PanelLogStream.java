package server;

import java.io.ByteArrayOutputStream;
import java.io.OutputStream;
import java.io.PrintStream;
import java.nio.charset.StandardCharsets;

public final class PanelLogStream extends OutputStream {

    private static ServerPanel target;
    private final ByteArrayOutputStream lineBytes = new ByteArrayOutputStream();

    private PanelLogStream() {
    }

    public static void bind(ServerPanel panel) {
        target = panel;
        PrintStream stream = new PrintStream(new PanelLogStream(), true, StandardCharsets.UTF_8);
        System.setOut(stream);
        System.setErr(stream);
    }

    @Override
    public void write(int b) {
        if (b == '\r') {
            return;
        }
        if (b == '\n') {
            flushLine();
            return;
        }
        lineBytes.write(b);
    }

    @Override
    public void write(byte[] bytes, int off, int len) {
        for (int i = off; i < off + len; i++) {
            write(bytes[i]);
        }
    }

    @Override
    public void flush() {
        if (lineBytes.size() > 0) {
            flushLine();
        }
    }

    private void flushLine() {
        byte[] data = lineBytes.toByteArray();
        lineBytes.reset();
        if (data.length == 0) {
            return;
        }

        String text = new String(data, StandardCharsets.UTF_8);
        text = text.replaceAll("\u001B\\[[0-9;]*m", "");
        if (target != null && !text.isEmpty()) {
            target.appendLog(text);
        }
    }
}
