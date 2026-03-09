package server;

import consts.ConstNpc;
import managers.GiftCodeManager;
import item.Item;
import player.Pet;
import player.Player;
import network.SessionManager;
import services.ItemService;
import services.PetService;
import services.Service;
import services.func.Input;
import map.Service.ChangeMapService;
import map.Service.NpcService;
import player.Service.InventoryService;
import utils.SystemMetrics;

import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.function.BiConsumer;
import java.util.function.Consumer;
import services.TaskService;

public class Command {

    private static Command instance;

    private final Map<String, Consumer<Player>> adminCommands = new HashMap<>();
    private final Map<String, BiConsumer<Player, String>> parameterizedCommands = new HashMap<>();

    public static Command gI() {
        if (instance == null) {
            instance = new Command();
        }
        return instance;
    }

    private Command() {
        initAdminCommands();
        initParameterizedCommands();
    }
    private void initAdminCommands() {
    adminCommands.put("item", player -> Input.gI().createFormGiveItem(player));
    adminCommands.put("getitem", player -> Input.gI().createFormGetItem(player));
    adminCommands.put("hoiskill", player -> Service.gI().releaseCooldownSkill(player));
    adminCommands.put("d", player -> Service.gI().setPos(player, player.location.x, player.location.y + 10));
    adminCommands.put("menu", player -> NpcService.gI().createMenuConMeo(player, ConstNpc.MENU_ADMIN, -1,
                "|0|Time start: " + ServerManager.timeStart 
                + "\nClients: " + Client.gI().getPlayers().size()
                + " người chơi\n Sessions: " + SessionManager.gI().getNumSession() 
                + "\nThreads: " + Thread.activeCount()
                + " luồng" + "\n" + SystemMetrics.ToString(),
                "Ngọc rồng", "Đệ tử", "Bảo trì", "Tìm kiếm\nngười chơi", "Boss", "Đóng"));
    }

    private void initParameterizedCommands() {
    parameterizedCommands.put("m ", (player, text) -> {
            int mapId = Integer.parseInt(text.replace("m ", ""));
            ChangeMapService.gI().changeMapInYard(player, mapId, -1, -1);
        });

    parameterizedCommands.put("toado", (player, text) -> {
            Service.gI().sendThongBaoOK(player, "x: " + player.location.x + " - y: " + player.location.y);
        });
    parameterizedCommands.put("n", (player, text) -> {
                    int idTask = Integer.parseInt(text.replaceAll("n", ""));
                    player.playerTask.taskMain.id = idTask - 1;
                    player.playerTask.taskMain.index = 0;
                    TaskService.gI().sendNextTaskMain(player);
            });
        parameterizedCommands.put("i ", (player, text) -> {
            int itemId = Integer.parseInt(text.replace("i ", ""));
            Item item = ItemService.gI().createNewItem(((short) itemId));
            List<Item.ItemOption> ops = ItemService.gI().getListOptionItemShop((short) itemId);
            if (!ops.isEmpty()) {
                item.itemOptions = ops;
            }
            InventoryService.gI().addItemBag(player, item);
            InventoryService.gI().sendItemBags(player);
            Service.gI().sendThongBao(player, "GET " + item.template.name + " [" + item.template.id + "] SUCCESS !");
        });
    }

    public void chat(Player player, String text) {
        if (!check(player, text)) {
            Service.gI().chat(player, text);
        }
    }

    public boolean check(Player player, String text) {
        if (player.isAdmin()) {
            if (adminCommands.containsKey(text)) {
                adminCommands.get(text).accept(player);
                return true;
            }

            for (Map.Entry<String, BiConsumer<Player, String>> entry : parameterizedCommands.entrySet()) {
                if (text.startsWith(entry.getKey())) {
                    entry.getValue().accept(player, text);
                    return true;
                }
            }
        }

        if (text.startsWith("ten con la ")) {
            PetService.gI().changeNamePet(player, text.replaceAll("ten con la ", ""));
        }

        if (player.pet != null) {
            switch (text) {
                case "di theo", "follow" ->
                    player.pet.changeStatus(Pet.FOLLOW);
                case "bao ve", "protect" ->
                    player.pet.changeStatus(Pet.PROTECT);
                case "tan cong", "attack" ->
                    player.pet.changeStatus(Pet.ATTACK);
                case "ve nha", "go home" ->
                    player.pet.changeStatus(Pet.GOHOME);
                case "bien hinh" ->
                    player.pet.transform();
            }
        }
        return false;
    }
}
