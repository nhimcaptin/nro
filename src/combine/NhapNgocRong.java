package combine;

import consts.ConstNpc;
import item.Item;
import player.Player;
import player.Service.InventoryService;
import services.ItemService;
import services.Service;

public class NhapNgocRong {

    private static final int MIN_ITEM_ID = 15;
    private static final int MAX_ITEM_ID = 20;
    private static final int REQUIRED_QUANTITY = 7;

    public static void showInfoCombine(Player player) {
        if (InventoryService.gI().getCountEmptyBag(player) <= 0) {
            CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.IGNORE_MENU,
                    "Hành trang cần ít nhất 1 chỗ trống", "Đóng");
            return;
        }
        if (player.combineNew.itemsCombine.size() != 1) {
            CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.IGNORE_MENU,
                    "Cần 7 viên ngọc rồng 2 sao trở lên", "Đóng");
            return;
        }
        Item item = player.combineNew.itemsCombine.get(0);
        if (!isValidItemForCombine(item)) {
            CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.IGNORE_MENU,
                    "Cần 7 viên ngọc rồng 2 sao trở lên", "Đóng");
            return;
        }
        String outName = ItemService.gI().getTemplate((short) (item.template.id - 1)).name;
        int maxTimes = item.quantity / REQUIRED_QUANTITY;
        String npcSay = "|2|Con có muốn biến 7 " + item.template.name + " thành\n"
                + "1 viên " + outName + "\n"
                + "|7|Cần 7 " + item.template.name + " mỗi lần\n"
                + "|1|Hiện có thể gộp tối đa " + maxTimes + " lần";
        CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.MENU_START_COMBINE, npcSay,
                "Gộp 1 lần", "Gộp 10 lần", "Gộp 100 lần");
    }

    public static void nhapNgocRong(Player player, int... numm) {
        int n = (numm.length > 0 && numm[0] > 0) ? numm[0] : 1;
        if (InventoryService.gI().getCountEmptyBag(player) <= 0 || player.combineNew.itemsCombine.isEmpty()) {
            return;
        }
        Item item = player.combineNew.itemsCombine.get(0);
        if (item == null || !item.isNotNullItem()
                || item.template.id < MIN_ITEM_ID || item.template.id > MAX_ITEM_ID) {
            return;
        }

        int done = 0;
        short outId = (short) (item.template.id - 1);
        for (int i = 0; i < n; i++) {
            if (item.quantity < REQUIRED_QUANTITY) {
                break;
            }
            if (InventoryService.gI().getCountEmptyBag(player) <= 0
                    && InventoryService.gI().findItemBag(player, outId) == null) {
                break;
            }
            InventoryService.gI().subQuantityItemsBag(player, item, REQUIRED_QUANTITY);
            Item nr = ItemService.gI().createNewItem(outId);
            InventoryService.gI().addItemBag(player, nr);
            done++;
        }

        if (done > 0) {
            CombineService.gI().sendEffectCombineDB(player, item.template.iconID);
            if (done > 1) {
                Service.gI().sendThongBao(player, "Đã gộp thành công " + done + " lần!");
            }
            InventoryService.gI().sendItemBags(player);
            CombineService.gI().reOpenItemCombine(player);
        } else {
            Service.gI().sendThongBao(player, "Không đủ ngọc rồng để gộp");
        }
    }

    private static boolean isValidItemForCombine(Item item) {
        return item != null && item.isNotNullItem()
                && item.template.id >= MIN_ITEM_ID
                && item.template.id <= MAX_ITEM_ID
                && item.quantity >= REQUIRED_QUANTITY;
    }
}
