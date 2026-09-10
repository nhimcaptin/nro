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
import services.PetService;
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
        String type;
        int playerId;
        String container;
        int slot;
        int itemId;
        int quantity;
        String options;
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
                        "select id, type, player_id, container, slot, item_id, quantity, options from admin_command "
                        + "where type in ('recall_item', 'give_item', 'give_pet') and status = 'pending' order by id asc")) {
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    Command command = new Command();
                    command.id = rs.getInt("id");
                    command.type = rs.getString("type");
                    command.playerId = rs.getInt("player_id");
                    command.container = rs.getString("container");
                    command.slot = rs.getInt("slot");
                    command.itemId = rs.getInt("item_id");
                    command.quantity = rs.getInt("quantity");
                    command.options = rs.getString("options");
                    commands.add(command);
                }
            }
        }

        for (Command command : commands) {
            try {
                if ("give_pet".equals(command.type)) {
                    givePet(command);
                } else if ("give_item".equals(command.type)) {
                    giveItem(command);
                } else {
                    recallItem(command);
                }
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

        int quantity = command.quantity <= 0 ? item.quantity : command.quantity;
        if (quantity > item.quantity) {
            finish(command.id, "failed", "Người chơi chỉ còn " + item.quantity + " vật phẩm, ít hơn số lượng cần thu hồi.");
            return;
        }

        String itemName = item.template.name;
        if (quantity >= item.quantity) {
            items.set(command.slot, ItemService.gI().createItemNull());
        } else {
            item.quantity -= quantity;
        }

        InventoryService.gI().sendItemBody(player);
        InventoryService.gI().sendItemBags(player);
        InventoryService.gI().sendItemBox(player);
        Service.gI().sendThongBao(player, "Vật phẩm " + itemName + " x" + quantity + " đã bị quản trị viên thu hồi");
        PlayerDAO.updatePlayer(player, false);

        finish(command.id, "done", "Đã thu hồi " + itemName + " x" + quantity + " khi người chơi đang online.");
    }

    private void givePet(Command command) throws Exception {
        Player player = Client.gI().getPlayerByID(command.playerId);
        if (player == null || player.isOffline) {
            return;
        }

        boolean replace = "replace".equals(command.options);
        if (player.pet != null && !replace) {
            finish(command.id, "failed", "Nhân vật đã có đệ tử.");
            return;
        }
        if (!PetService.gI().grantPetByAdmin(player, command.itemId, command.quantity, replace)) {
            finish(command.id, "failed", "Loại đệ tử hoặc hành tinh không hợp lệ.");
            return;
        }

        PlayerDAO.updatePlayer(player, false);
        Service.gI().sendThongBao(player, "Bạn đã được quản trị viên cấp đệ tử mới.");
        finish(command.id, "done", "Đã cấp đệ tử khi người chơi online.");
    }

    private void giveItem(Command command) throws Exception {
        Player player = Client.gI().getPlayerByID(command.playerId);
        if (player == null || player.isOffline) {
            finish(command.id, "offline", "Người chơi không online, hãy cấp lại khi offline.");
            return;
        }

        Item item;
        try {
            item = ItemService.gI().createNewItem((short) command.itemId, Math.max(1, command.quantity));
        } catch (Exception e) {
            item = null;
        }
        if (item == null || item.template == null) {
            finish(command.id, "failed", "Không có vật phẩm id " + command.itemId + ".");
            return;
        }
        for (int[] option : parseOptions(command.options)) {
            item.itemOptions.add(new Item.ItemOption(option[0], option[1]));
        }

        boolean added;
        if ("items_box".equals(command.container)) {
            added = putInto(player.inventory.itemsBox, item);
        } else {
            added = InventoryService.gI().addItemBag(player, item);
        }
        if (!added) {
            finish(command.id, "failed", "Túi đồ của người chơi đã đầy.");
            return;
        }

        InventoryService.gI().sendItemBags(player);
        InventoryService.gI().sendItemBox(player);
        Service.gI().sendThongBao(player, "Bạn được quản trị viên cấp " + item.template.name + " x" + item.quantity);
        PlayerDAO.updatePlayer(player, false);

        finish(command.id, "done", "Đã cấp " + item.template.name + " x" + item.quantity + " khi người chơi đang online.");
    }

    private boolean putInto(List<Item> items, Item item) {
        for (int i = 0; i < items.size(); i++) {
            Item slot = items.get(i);
            if (slot == null || !slot.isNotNullItem()) {
                items.set(i, item);
                return true;
            }
        }
        return false;
    }

    /** Chuỗi "21:80,47:2000" từ web */
    private List<int[]> parseOptions(String options) {
        List<int[]> result = new ArrayList<>();
        if (options == null || options.isEmpty()) {
            return result;
        }
        for (String part : options.split(",")) {
            String[] pair = part.trim().split(":");
            if (pair.length == 2) {
                try {
                    result.add(new int[]{Integer.parseInt(pair[0].trim()), Integer.parseInt(pair[1].trim())});
                } catch (NumberFormatException ignored) {
                }
            }
        }
        return result;
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
