package combine;

import consts.ConstNpc;
import item.Item;
import player.Player;
import player.Service.InventoryService;
import services.ItemService;
import services.Service;
import utils.Util;

public class NangCapItemCap2 {

    private static final int GOLD_TAO_DA = 50_000_000;
    private static final int RATIO_TAO_DA = 80;
    private static final int ITEM_ID_C1_MIN = 381;
    private static final int ITEM_ID_C1_MAX = 385;
    private static final int ITEM_C2_ID_MIN = 1150;
    private static final int ITEM_C2_ID_MAX = 1154;
    private static final int C2_ITEM_COUNT = 10;
    private static final String ITEM_C1_NAME = "Item Cấp 1";
    private static final String ITEM_C2_NAME = "Item Cấp 2";

    public static void showInfoCombine(Player player) {
        if (player.combineNew.itemsCombine.size() != 1) {
            CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.IGNORE_MENU,
                    "Cần 10 " + ITEM_C1_NAME, "Đóng");
            return;
        }
        Item itemc1 = player.combineNew.itemsCombine.get(0);
        if (itemc1.template.id < ITEM_ID_C1_MIN || itemc1.template.id > ITEM_ID_C1_MAX
                || itemc1.quantity < C2_ITEM_COUNT) {
            CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.IGNORE_MENU,
                    "Cần 10 " + ITEM_C1_NAME, "Đóng");
            return;
        }

        player.combineNew.goldCombine = GOLD_TAO_DA;
        player.combineNew.ratioCombine = RATIO_TAO_DA;
        int maxByItem = itemc1.quantity / C2_ITEM_COUNT;
        int maxByGold = (int) (player.inventory.gold / GOLD_TAO_DA);
        int maxTimes = Math.min(maxByItem, maxByGold);

        String npcSay = "|2|Tạo " + ITEM_C2_NAME + " từ " + ITEM_C1_NAME + "\n";
        npcSay += "|2|Cần 10 " + ITEM_C1_NAME + " mỗi lần\n";
        npcSay += "|2|Tỉ lệ thành công: " + RATIO_TAO_DA + "%\n";
        npcSay += "|2|Cần: " + Util.numberToMoney(GOLD_TAO_DA) + " vàng mỗi lần\n";
        npcSay += "|7|Thất bại vẫn mất nguyên liệu + vàng\n";
        npcSay += "|1|Hiện có thể nâng tối đa " + Math.max(maxTimes, 0) + " lần";

        if (player.inventory.gold < GOLD_TAO_DA) {
            npcSay += "\n|7|Còn thiếu " + Util.powerToString(GOLD_TAO_DA - player.inventory.gold) + " vàng";
            CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.IGNORE_MENU, npcSay, "Đóng");
            return;
        }

        CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.MENU_START_COMBINE, npcSay,
                "Nâng 1 lần\n" + Util.numberToMoney(GOLD_TAO_DA) + " vàng",
                "Nâng 10 lần",
                "Nâng 100 lần");
    }

    public static void Itemc2(Player player, int... numm) {
        int n = (numm.length > 0 && numm[0] > 0) ? numm[0] : 1;
        if (player.combineNew.itemsCombine.size() != 1) {
            return;
        }
        Item itemc1 = player.combineNew.itemsCombine.get(0);
        if (itemc1 == null || itemc1.template == null
                || itemc1.template.id < ITEM_ID_C1_MIN || itemc1.template.id > ITEM_ID_C1_MAX) {
            return;
        }

        int success = 0;
        int fail = 0;
        int done = 0;

        for (int i = 0; i < n; i++) {
            if (player.inventory.gold < GOLD_TAO_DA || itemc1.quantity < C2_ITEM_COUNT) {
                break;
            }
            if (InventoryService.gI().getCountEmptyBag(player) <= 0) {
                if (done == 0) {
                    Service.gI().sendThongBao(player, "Hành trang cần ít nhất 1 chỗ trống");
                } else {
                    Service.gI().sendThongBao(player, "Hành trang đầy, dừng sau " + done + " lần");
                }
                break;
            }

            player.inventory.gold -= GOLD_TAO_DA;
            InventoryService.gI().subQuantityItemsBag(player, itemc1, C2_ITEM_COUNT);
            done++;

            if (Util.isTrue(RATIO_TAO_DA, 100)) {
                int randomId = Util.nextInt(ITEM_C2_ID_MIN, ITEM_C2_ID_MAX);
                Item itemc2 = ItemService.gI().createNewItem((short) randomId);
                InventoryService.gI().addItemBag(player, itemc2);
                success++;
            } else {
                fail++;
            }
        }

        if (done <= 0) {
            Service.gI().sendThongBao(player, "Không đủ nguyên liệu hoặc vàng để thực hiện");
            return;
        }

        if (success > 0) {
            CombineService.gI().sendEffectSuccessCombine(player);
        } else {
            CombineService.gI().sendEffectFailCombine(player);
        }

        if (n > 1) {
            Service.gI().sendThongBao(player,
                    "Đã nâng " + done + " lần: thành công " + success + ", thất bại " + fail);
        }

        InventoryService.gI().sendItemBags(player);
        Service.gI().sendMoney(player);
        CombineService.gI().reOpenItemCombine(player);
    }
}
