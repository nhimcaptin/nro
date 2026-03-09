package npc.list;
import consts.ConstNpc;
import item.Item;
import npc.Npc;
import player.Player;
import player.Service.InventoryService;
import services.ItemService;
import services.Service;
import services.TaskService;
import shop.ShopService;
import utils.Util;

public class BulmaTuongLai extends Npc {

    public BulmaTuongLai(int mapId, int status, int cx, int cy, int tempId, int avartar) {
        super(mapId, status, cx, cy, tempId, avartar);
    }

    @Override
    public void openBaseMenu(Player player) {
        if (canOpenNpc(player)) {
            if (this.mapId == 102) {
                if (!TaskService.gI().checkDoneTaskTalkNpc(player, this)) {
                    this.createOtherMenu(player, ConstNpc.BASE_MENU, "bạn muốn gì ở tôi?", "Cửa hàng");
                }
            }
        }
    }

    @Override
    public void confirmMenu(Player player, int select) {
        if (canOpenNpc(player)) {
            if (this.mapId == 102) {
                if (player.idMark.isBaseMenu()) {
                    switch (select) {
                        case 0 ->
                            ShopService.gI().opendShop(player, "BUNMA_FUTURE", true);
                    }
                }
            }
        }
    }
}
