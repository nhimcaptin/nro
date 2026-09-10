package managers;

import database.DatabaseManager;
import database.PlayerDAO;
import item.Item;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.util.ArrayList;
import java.util.List;
import player.Player;
import player.Service.InventoryService;
import server.Client;
import services.ItemService;
import services.Service;
import utils.Logger;

/**
 * Nhận lệnh quản trị từ web (bảng admin_command) và áp dụng ngay cho người chơi
 * đang online, tránh việc sửa trực tiếp database bị server ghi đè khi lưu.
 */
public class AdminCommandManager implements Runnable {

    private static final long POLL_INTERVAL = 5000;

    private static AdminCommandManager instance;

    public static AdminCommandManager gI() {
        if (instance == null) {
            instance = new AdminCommandManager();
        }
        return instance;
    }

    private static class Command {

        int id;
        int playerId;
        String container;
        int slot;
        int itemId;
    }

    @Override
    public void run() {
        while (true) {
            try {
                process();
            } catch (Exception e) {
                Logger.log(Logger.RED, "Lỗi xử lý admin_command: " + e.getMessage() + "\n");
            }
            try {
                Thread.sleep(POLL_INTERVAL);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return;
            }
        }
    }

    private void process() throws Exception {
        List<Command> commands = new ArrayList<>();
        try (Connection con = DatabaseManager.getConnection();
                PreparedStatement ps = con.prepareStatement(
                        "select id, player_id, container, slot, item_id from admin_command "
                        + "where type = 'recall_item' and status = 'pending' order by id asc limit 50")) {
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    Command command = new Command();
                    command.id = rs.getInt("id");
                    command.playerId = rs.getInt("player_id");
                    command.container = rs.getString("container");
                    command.slot = rs.getInt("slot");
                    command.itemId = rs.getInt("item_id");
                    commands.add(command);
                }
            }
        }

        for (Command command : commands) {
            try {
                recallItem(command);
            } catch (Exception e) {
                finish(command.id, "failed", "Lỗi: " + e.getMessage());
            }
        }
    }

    private void recallItem(Command command) throws Exception {
        Player player = Client.gI().getPlayerByID(command.playerId);
        if (player == null || player.isOffline) {
            finish(command.id, "offline", "Người chơi không online, hãy thu hồi trực tiếp khi offline.");
            return;
        }

        List<Item> items = getContainer(player, command.container);
        if (items == null) {
            finish(command.id, "failed", "Loại túi đồ không hợp lệ: " + command.container);
            return;
        }
        if (command.slot < 0 || command.slot >= items.size()) {
            finish(command.id, "failed", "Ô đồ không tồn tại.");
            return;
        }

        Item item = items.get(command.slot);
        if (item == null || !item.isNotNullItem()) {
            finish(command.id, "failed", "Ô đồ đang trống.");
            return;
        }
        if (item.template.id != command.itemId) {
            finish(command.id, "failed", "Vật phẩm trong ô đã thay đổi, hãy tải lại trang và thu hồi lại.");
            return;
        }

        String itemName = item.template.name;
        items.set(command.slot, ItemService.gI().createItemNull());

        InventoryService.gI().sendItemBody(player);
        InventoryService.gI().sendItemBags(player);
        InventoryService.gI().sendItemBox(player);
        Service.gI().sendThongBao(player, "Vật phẩm " + itemName + " đã bị quản trị viên thu hồi");
        PlayerDAO.updatePlayer(player, false);

        finish(command.id, "done", "Đã thu hồi " + itemName + " khi người chơi đang online.");
    }

    private List<Item> getContainer(Player player, String container) {
        if (container == null) {
            return null;
        }
        switch (container) {
            case "items_body":
                return player.inventory.itemsBody;
            case "items_bag":
                return player.inventory.itemsBag;
            case "items_box":
                return player.inventory.itemsBox;
            case "items_box_lucky_round":
                return player.inventory.itemsBoxCrackBall;
            case "items_daban":
                return player.inventory.itemsDaBan;
            default:
                return null;
        }
    }

    private void finish(int commandId, String status, String message) {
        try {
            DatabaseManager.executeUpdate(
                    "update admin_command set status = ?, message = ?, processed_at = now() where id = ?",
                    status, message, commandId);
        } catch (Exception e) {
            Logger.log(Logger.RED, "Không cập nhật được admin_command #" + commandId + ": " + e.getMessage() + "\n");
        }
    }
}
