package server;

import boss.Boss;
import boss.BossManager.BossManager;
import boss.BossManager.BossRegistry;
import java.awt.BorderLayout;
import java.awt.Color;
import java.awt.Dimension;
import java.awt.FlowLayout;
import java.awt.Font;
import java.awt.GridLayout;
import java.awt.event.WindowAdapter;
import java.awt.event.WindowEvent;
import java.util.List;
import javax.swing.BorderFactory;
import javax.swing.JButton;
import javax.swing.JFrame;
import javax.swing.JLabel;
import javax.swing.JOptionPane;
import javax.swing.JPanel;
import javax.swing.JScrollPane;
import javax.swing.JSpinner;
import javax.swing.JTabbedPane;
import javax.swing.JTable;
import javax.swing.JTextArea;
import javax.swing.JTextField;
import javax.swing.JTextPane;
import javax.swing.SpinnerNumberModel;
import javax.swing.SwingConstants;
import javax.swing.SwingUtilities;
import javax.swing.Timer;
import javax.swing.table.DefaultTableModel;
import javax.swing.text.BadLocationException;
import javax.swing.text.SimpleAttributeSet;
import javax.swing.text.StyleConstants;
import javax.swing.text.StyledDocument;
import network.SessionManager;
import utils.SystemMetrics;

public class ServerPanel extends JFrame {

    private static ServerPanel instance;

    private final JLabel lblOnline = new JLabel("Online: 0", SwingConstants.CENTER);
    private final JLabel lblSessions = new JLabel("Sessions: 0", SwingConstants.CENTER);
    private final JLabel lblThreads = new JLabel("Threads: 0", SwingConstants.CENTER);
    private final JLabel lblStatus = new JLabel("Chưa chạy", SwingConstants.CENTER);
    private final JLabel lblUptime = new JLabel("Chưa khởi động", SwingConstants.CENTER);
    private final JTextArea txtMetrics = new JTextArea();
    private final DefaultTableModel bossModel = new DefaultTableModel(
            new String[]{"Tên Boss", "Trạng thái", "Map", "Khu", "HP"}, 0) {
        @Override
        public boolean isCellEditable(int row, int column) {
            return false;
        }
    };
    private final JTable bossTable = new JTable(bossModel);
    private final JTextPane txtConsole = new JTextPane();
    private final JTextField txtMaintMinutes = new JTextField("5", 4);

    private static final int MAX_CONSOLE_LINES = 1500;

    private final RateField expRate = new RateField("EXP server (x)", DropRateConfig.RATE_EXP, 1, 100);
    private final RateField manhDa = new RateField("Mảnh đá vụn", DropRateConfig.MANH_DA_VUN_NUM, DropRateConfig.MANH_DA_VUN_DEN);
    private final RateField setSkh = new RateField("Set kích hoạt", DropRateConfig.SET_SKH_NUM, DropRateConfig.SET_SKH_DEN);
    private final RateField itemSkh = new RateField("Item SKH", DropRateConfig.ITEM_SKH_NUM, DropRateConfig.ITEM_SKH_DEN);
    private final RateField doSaoSkh = new RateField("Đồ sao SKH", DropRateConfig.DO_SAO_SKH_NUM, DropRateConfig.DO_SAO_SKH_DEN);
    private final RateField ngoc = new RateField("Ngọc rơi", DropRateConfig.NGOC_NUM, DropRateConfig.NGOC_DEN);
    private final RateField gold3Planets = new RateField("Vàng 3 hành tinh", DropRateConfig.GOLD_3_PLANETS_NUM, DropRateConfig.GOLD_3_PLANETS_DEN);
    private final RateField goldMap = new RateField("Vàng map khác", DropRateConfig.GOLD_MAP_NUM, DropRateConfig.GOLD_MAP_DEN);
    private final RateField ngocRong = new RateField("Ngọc rồng 1-7 sao", DropRateConfig.NGOC_RONG_NUM, DropRateConfig.NGOC_RONG_DEN);
    private final RateField doTlCold = new RateField("Đồ TL map Cold", DropRateConfig.DO_TL_COLD_NUM, DropRateConfig.DO_TL_COLD_DEN);
    private final RateField farmNgoc = new RateField("Farm ngọc xanh", DropRateConfig.FARM_NGOC_NUM, DropRateConfig.FARM_NGOC_DEN);
    private final RateField farmThoiVang = new RateField("Farm thỏi vàng", DropRateConfig.FARM_THOI_VANG_NUM, DropRateConfig.FARM_THOI_VANG_DEN);
    private final RateField bossReward = new RateField("Boss reward", BossManager.ratioReward, 1, 100);

    public static ServerPanel gI() {
        if (instance == null) {
            instance = new ServerPanel();
        }
        return instance;
    }

    public ServerPanel() {
        super(ServerManager.NAME_SERVER + " - Control Panel");
        initUi();
        startRefreshTimer();
    }

    public void bootstrap() {
        PanelLogStream.bind(this);
        appendLog("=== " + ServerManager.NAME_SERVER + " - Control Panel ===");
        appendLog("Port: " + ServerManager.PORT + " | Auto maintenance: " + AutoMaintenance.getScheduleText());
        startServerFromPanel();
    }

    public void appendLog(String line) {
        SwingUtilities.invokeLater(() -> {
            StyledDocument doc = txtConsole.getStyledDocument();
            SimpleAttributeSet attrs = new SimpleAttributeSet();
            StyleConstants.setFontFamily(attrs, "Consolas");
            StyleConstants.setFontSize(attrs, 13);
            StyleConstants.setForeground(attrs, new Color(229, 231, 235));
            try {
                doc.insertString(doc.getLength(), line + "\n", attrs);
                trimConsole(doc);
                txtConsole.setCaretPosition(doc.getLength());
            } catch (BadLocationException ignored) {
            }
        });
    }

    private void initUi() {
        setDefaultCloseOperation(JFrame.DO_NOTHING_ON_CLOSE);
        setMinimumSize(new Dimension(900, 620));
        setLocationRelativeTo(null);

        addWindowListener(new WindowAdapter() {
            @Override
            public void windowClosing(WindowEvent e) {
                confirmShutdown();
            }
        });

        txtConsole.setEditable(false);
        txtConsole.setBackground(new Color(17, 24, 39));
        txtConsole.setFont(new Font("Consolas", Font.PLAIN, 13));

        add(buildHeader(), BorderLayout.NORTH);
        add(buildTabs(), BorderLayout.CENTER);

        styleHeaderLabel(lblOnline);
        styleHeaderLabel(lblSessions);
        styleHeaderLabel(lblThreads);
        lblStatus.setFont(lblStatus.getFont().deriveFont(Font.BOLD, 14f));
        updateServerStatusLabel();
    }

    private JPanel buildHeader() {
        JPanel header = new JPanel(new GridLayout(2, 1, 8, 8));
        header.setBorder(BorderFactory.createEmptyBorder(12, 12, 8, 12));

        JPanel stats = new JPanel(new GridLayout(1, 4, 8, 0));
        stats.add(lblOnline);
        stats.add(lblSessions);
        stats.add(lblThreads);
        stats.add(lblStatus);
        header.add(stats);

        JPanel footer = new JPanel(new BorderLayout(8, 0));
        footer.add(lblUptime, BorderLayout.CENTER);
        header.add(footer);
        return header;
    }

    private JTabbedPane buildTabs() {
        JTabbedPane tabs = new JTabbedPane();
        tabs.addTab("Console", buildConsoleTab());
        tabs.addTab("Tổng quan", buildOverviewTab());
        tabs.addTab("Boss đang sống", buildBossTab());
        tabs.addTab("Tỷ lệ rơi đồ", buildDropRateTab());
        tabs.addTab("Bảo trì", buildMaintenanceTab());
        return tabs;
    }

    private JPanel buildConsoleTab() {
        JPanel panel = new JPanel(new BorderLayout(8, 8));
        panel.setBorder(BorderFactory.createEmptyBorder(12, 12, 12, 12));

        JLabel hint = new JLabel("Log server");
        panel.add(hint, BorderLayout.NORTH);
        panel.add(new JScrollPane(txtConsole), BorderLayout.CENTER);
        return panel;
    }

    private JPanel buildOverviewTab() {
        JPanel panel = new JPanel(new BorderLayout(8, 8));
        panel.setBorder(BorderFactory.createEmptyBorder(12, 12, 12, 12));

        txtMetrics.setEditable(false);
        txtMetrics.setFont(new Font("Consolas", Font.PLAIN, 13));
        txtMetrics.setBackground(new Color(17, 24, 39));
        txtMetrics.setForeground(new Color(229, 231, 235));
        txtMetrics.setBorder(BorderFactory.createEmptyBorder(10, 10, 10, 10));

        JPanel info = new JPanel(new GridLayout(0, 1, 4, 4));
        info.add(new JLabel("Server: " + ServerManager.NAME_SERVER));
        info.add(new JLabel("IP:Port: " + ServerManager.IP + " / " + ServerManager.PORT));
        info.add(new JLabel("Domain: " + ServerManager.DOMAIN));

        panel.add(info, BorderLayout.NORTH);
        panel.add(new JScrollPane(txtMetrics), BorderLayout.CENTER);
        return panel;
    }

    private JPanel buildBossTab() {
        JPanel panel = new JPanel(new BorderLayout(8, 8));
        panel.setBorder(BorderFactory.createEmptyBorder(12, 12, 12, 12));

        bossTable.setRowHeight(24);
        bossTable.getColumnModel().getColumn(0).setPreferredWidth(180);
        bossTable.getColumnModel().getColumn(2).setPreferredWidth(200);

        JLabel hint = new JLabel("Danh sách boss chưa chết (tự cập nhật mỗi 2 giây)");
        panel.add(hint, BorderLayout.NORTH);
        panel.add(new JScrollPane(bossTable), BorderLayout.CENTER);
        return panel;
    }

    private JPanel buildDropRateTab() {
        JPanel panel = new JPanel(new BorderLayout(8, 8));
        panel.setBorder(BorderFactory.createEmptyBorder(12, 12, 12, 12));

        JPanel form = new JPanel(new GridLayout(0, 1, 6, 6));
        for (RateField field : new RateField[]{
            expRate, manhDa, setSkh, itemSkh, doSaoSkh, ngoc, gold3Planets, goldMap, ngocRong, doTlCold, farmNgoc, farmThoiVang, bossReward
        }) {
            form.add(field.buildRow());
        }

        JButton save = new JButton("Lưu tỷ lệ");
        save.addActionListener(e -> saveDropRates());

        JLabel note = new JLabel("<html>Tỷ lệ dạng <b>num / den</b> (vd: 10/100 = 10%). Thay đổi có hiệu lực ngay.</html>");
        JPanel bottom = new JPanel(new BorderLayout());
        bottom.add(note, BorderLayout.CENTER);
        bottom.add(save, BorderLayout.EAST);

        panel.add(new JScrollPane(form), BorderLayout.CENTER);
        panel.add(bottom, BorderLayout.SOUTH);
        return panel;
    }

    private JPanel buildMaintenanceTab() {
        JPanel panel = new JPanel(new BorderLayout(8, 8));
        panel.setBorder(BorderFactory.createEmptyBorder(12, 12, 12, 12));

        JPanel actions = new JPanel(new FlowLayout(FlowLayout.LEFT, 10, 10));

        JButton btnNow = new JButton("Bảo trì ngay");
        btnNow.setBackground(new Color(220, 38, 38));
        btnNow.setForeground(Color.WHITE);
        btnNow.addActionListener(e -> startMaintenance(true));

        JButton btnDelay = new JButton("Bảo trì có countdown");
        btnDelay.addActionListener(e -> startMaintenance(false));

        actions.add(new JLabel("Countdown (phút):"));
        actions.add(txtMaintMinutes);
        actions.add(btnDelay);
        actions.add(btnNow);

        JTextArea help = new JTextArea(
                "Bảo trì ngay: kick toàn bộ player và lưu dữ liệu rồi tắt server.\n"
                + "Countdown: gửi thông báo trong game trước khi tắt.\n"
                + "Bảo trì tự động: " + AutoMaintenance.getScheduleText() + "\n"
                + "(Chỉnh trong Config.properties)\n"
                + "Đóng cửa sổ panel = bảo trì và tắt server."
        );
        help.setEditable(false);
        help.setLineWrap(true);
        help.setWrapStyleWord(true);
        help.setBackground(panel.getBackground());

        panel.add(actions, BorderLayout.NORTH);
        panel.add(help, BorderLayout.CENTER);
        return panel;
    }

    private void trimConsole(StyledDocument doc) throws BadLocationException {
        int lines = doc.getLength() > 0 ? doc.getText(0, doc.getLength()).split("\n", -1).length : 0;
        if (lines <= MAX_CONSOLE_LINES) {
            return;
        }
        int removeUntil = 0;
        int count = 0;
        String text = doc.getText(0, doc.getLength());
        for (int i = 0; i < text.length(); i++) {
            if (text.charAt(i) == '\n') {
                count++;
                if (count >= lines - MAX_CONSOLE_LINES) {
                    removeUntil = i + 1;
                    break;
                }
            }
        }
        if (removeUntil > 0) {
            doc.remove(0, removeUntil);
        }
    }

    private void startServerFromPanel() {
        if (ServerManager.isRunning) {
            return;
        }
        lblStatus.setText("Đang khởi động...");
        lblStatus.setForeground(new Color(234, 179, 8));
        appendLog("Đang khởi động server...");
        ServerManager.startServer();
        lblUptime.setText("Khởi động: " + ServerManager.timeStart);
        appendLog("Lệnh start server đã gửi lúc " + ServerManager.timeStart);
    }

    private void updateServerStatusLabel() {
        if (ServerManager.isRunning) {
            lblStatus.setText("Đang chạy");
            lblStatus.setForeground(new Color(34, 197, 94));
        } else if (Maintenance.isRunning) {
            lblStatus.setText("Đang bảo trì...");
            lblStatus.setForeground(new Color(234, 179, 8));
        } else {
            lblStatus.setText("Chưa chạy");
            lblStatus.setForeground(new Color(156, 163, 175));
        }
    }

    private void saveDropRates() {
        DropRateConfig.RATE_EXP = expRate.readIntValue();
        Manager.RATE_EXP_SERVER = (byte) Math.max(1, Math.min(100, DropRateConfig.RATE_EXP));

        DropRateConfig.MANH_DA_VUN_NUM = manhDa.readNum();
        DropRateConfig.MANH_DA_VUN_DEN = manhDa.readDen();
        DropRateConfig.SET_SKH_NUM = setSkh.readNum();
        DropRateConfig.SET_SKH_DEN = setSkh.readDen();
        DropRateConfig.ITEM_SKH_NUM = itemSkh.readNum();
        DropRateConfig.ITEM_SKH_DEN = itemSkh.readDen();
        DropRateConfig.DO_SAO_SKH_NUM = doSaoSkh.readNum();
        DropRateConfig.DO_SAO_SKH_DEN = doSaoSkh.readDen();
        DropRateConfig.NGOC_NUM = ngoc.readNum();
        DropRateConfig.NGOC_DEN = ngoc.readDen();
        DropRateConfig.GOLD_3_PLANETS_NUM = gold3Planets.readNum();
        DropRateConfig.GOLD_3_PLANETS_DEN = gold3Planets.readDen();
        DropRateConfig.GOLD_MAP_NUM = goldMap.readNum();
        DropRateConfig.GOLD_MAP_DEN = goldMap.readDen();
        DropRateConfig.NGOC_RONG_NUM = ngocRong.readNum();
        DropRateConfig.NGOC_RONG_DEN = ngocRong.readDen();
        DropRateConfig.DO_TL_COLD_NUM = doTlCold.readNum();
        DropRateConfig.DO_TL_COLD_DEN = doTlCold.readDen();
        DropRateConfig.FARM_NGOC_NUM = farmNgoc.readNum();
        DropRateConfig.FARM_NGOC_DEN = farmNgoc.readDen();
        DropRateConfig.FARM_THOI_VANG_NUM = farmThoiVang.readNum();
        DropRateConfig.FARM_THOI_VANG_DEN = farmThoiVang.readDen();
        BossManager.ratioReward = (byte) bossReward.readIntValue();

        JOptionPane.showMessageDialog(this, "Đã lưu tỷ lệ rơi đồ!", "Thành công", JOptionPane.INFORMATION_MESSAGE);
    }

    private void startMaintenance(boolean immediately) {
        if (Maintenance.isRunning) {
            JOptionPane.showMessageDialog(this, "Server đang trong quá trình bảo trì.", "Thông báo", JOptionPane.WARNING_MESSAGE);
            return;
        }

        int confirm = JOptionPane.showConfirmDialog(
                this,
                immediately ? "Bảo trì và tắt server ngay?" : "Bắt đầu countdown bảo trì?",
                "Xác nhận",
                JOptionPane.YES_NO_OPTION
        );
        if (confirm != JOptionPane.YES_OPTION) {
            return;
        }

        lblStatus.setText("Đang bảo trì...");
        lblStatus.setForeground(new Color(234, 179, 8));

        if (immediately) {
            Maintenance.gI().startImmediately();
        } else {
            int minutes;
            try {
                minutes = Integer.parseInt(txtMaintMinutes.getText().trim());
            } catch (NumberFormatException ex) {
                JOptionPane.showMessageDialog(this, "Nhập số phút hợp lệ.", "Lỗi", JOptionPane.ERROR_MESSAGE);
                return;
            }
            if (minutes <= 0) {
                JOptionPane.showMessageDialog(this, "Số phút phải lớn hơn 0.", "Lỗi", JOptionPane.ERROR_MESSAGE);
                return;
            }
            Maintenance.gI().startNew(minutes * 60);
        }
    }

    private void confirmShutdown() {
        if (Maintenance.isRunning) {
            dispose();
            return;
        }
        if (!ServerManager.isRunning) {
            dispose();
            System.exit(0);
            return;
        }
        int confirm = JOptionPane.showConfirmDialog(
                this,
                "Bảo trì và tắt server?",
                "Thoát",
                JOptionPane.YES_NO_OPTION
        );
        if (confirm == JOptionPane.YES_OPTION) {
            Maintenance.gI().startImmediately();
        }
    }

    private void startRefreshTimer() {
        Timer timer = new Timer(2000, e -> refreshStats());
        timer.start();
        refreshStats();
    }

    private void refreshStats() {
        SwingUtilities.invokeLater(() -> {
            updateServerStatusLabel();

            if (!ServerManager.isRunning) {
                return;
            }

            int online = Client.gI().getPlayers().size();
            int sessions = SessionManager.gI().getNumSession();

            lblOnline.setText("Online: " + online);
            lblSessions.setText("Sessions: " + sessions);
            lblThreads.setText("Threads: " + Thread.activeCount());

            txtMetrics.setText(
                    "Người chơi online: " + online + "\n"
                    + "Sessions: " + sessions + "\n"
                    + "Threads: " + Thread.activeCount() + "\n"
                    + "EXP rate: x" + Manager.RATE_EXP_SERVER + "\n"
                    + "Boss reward: x" + BossManager.ratioReward + "\n"
                    + "Auto maintenance: " + AutoMaintenance.getScheduleText() + "\n\n"
                    + SystemMetrics.ToString()
            );

            refreshBossTable();
        });
    }

    private void refreshBossTable() {
        bossModel.setRowCount(0);
        if (!ServerManager.isRunning) {
            return;
        }
        List<Boss> bosses = BossRegistry.getAliveBosses();
        for (Boss boss : bosses) {
            String mapInfo = boss.zone != null
                    ? boss.zone.map.mapName + " (" + boss.zone.map.mapId + ")"
                    : "Chưa vào map";
            String zoneInfo = boss.zone != null ? String.valueOf(boss.zone.zoneId) : "-";
            String hp = boss.nPoint != null ? boss.nPoint.hp + "/" + boss.nPoint.hpMax : "-";
            String name = boss.data != null && boss.data.length > 0 ? boss.data[0].getName() : "Boss";
            bossModel.addRow(new Object[]{
                name,
                boss.bossStatus != null ? boss.bossStatus.name() : "-",
                mapInfo,
                zoneInfo,
                hp
            });
        }
    }

    private void styleHeaderLabel(JLabel label) {
        label.setFont(label.getFont().deriveFont(Font.BOLD, 16f));
        label.setBorder(BorderFactory.createCompoundBorder(
                BorderFactory.createLineBorder(new Color(55, 65, 81)),
                BorderFactory.createEmptyBorder(8, 8, 8, 8)
        ));
    }

    private static final class RateField {
        private final String label;
        private final JSpinner numSpinner;
        private final JSpinner denSpinner;

        RateField(String label, int num, int den) {
            this(label, num, den, 1, Integer.MAX_VALUE);
        }

        RateField(String label, int value, int min, int max) {
            this.label = label;
            this.numSpinner = new JSpinner(new SpinnerNumberModel(value, min, max, 1));
            this.denSpinner = null;
        }

        RateField(String label, int num, int den, int min, int max) {
            this.label = label;
            this.numSpinner = new JSpinner(new SpinnerNumberModel(num, min, max, 1));
            this.denSpinner = new JSpinner(new SpinnerNumberModel(den, 1, max, 1));
        }

        JPanel buildRow() {
            JPanel row = new JPanel(new FlowLayout(FlowLayout.LEFT, 8, 0));
            row.add(new JLabel(label + ":"));
            row.add(numSpinner);
            if (denSpinner != null) {
                row.add(new JLabel("/"));
                row.add(denSpinner);
            }
            return row;
        }

        int readNum() {
            return ((Number) numSpinner.getValue()).intValue();
        }

        int readDen() {
            return denSpinner == null ? 1 : ((Number) denSpinner.getValue()).intValue();
        }

        int readIntValue() {
            return readNum();
        }
    }
}
