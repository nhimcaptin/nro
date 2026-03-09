package npc.list;

import clan.Clan;
import consts.ConstNpc;
import item.Item;
import java.util.ArrayList;
import Deputyhead.TreasureUnderSea;
import Deputyhead.Service.TreasureUnderSeaService;
import npc.Npc;
import static npc.NpcFactory.PLAYERID_OBJECT;
import player.Player;
import services.ItemService;
import player.Service.InventoryService;
import map.Service.NpcService;
import services.RewardService;
import services.Service;
import shop.ShopService;
import services.TaskService;
import map.Service.ChangeMapService;
import services.func.Input;
import player.Service.PlayerService;
import skill.Skill;
import utils.Logger;
import utils.SkillUtil;
import utils.TimeUtil;
import utils.Util;

public class QuyLaoKame extends Npc {
    public QuyLaoKame(int mapId, int status, int cx, int cy, int tempId, int avartar) {
        super(mapId, status, cx, cy, tempId, avartar);
    }

    @Override
    public void openBaseMenu(Player player) {
        Item ruacon = InventoryService.gI().findItemBag(player, 874);
        if (canOpenNpc(player)) {
            ArrayList<String> menu = new ArrayList<>();
            if (!player.canReward) {
                menu.add("Nói\nchuyện");
                if (ruacon != null && ruacon.quantity >= 1) {
                    menu.add("Giao\nRùa con");
                }
            } else {
                menu.add("Giao\nLân con");
            }
            String[] menus = menu.toArray(String[]::new);
            if (!TaskService.gI().checkDoneTaskTalkNpc(player, this)) {
                this.createOtherMenu(player, ConstNpc.BASE_MENU, "Con muốn hỏi gì nào?", menus);
            }
        }
    }

    @Override
    public void confirmMenu(Player player, int select) {
        if (!canOpenNpc(player)) return;
        if (player.canReward) {
            RewardService.gI().rewardLancon(player);
            return;
        }

        switch (player.idMark.getIndexMenu()) {
            case ConstNpc.BASE_MENU:
                if (select == 0) {
                    if (player.LearnSkill.Time != -1 && player.LearnSkill.Time <= System.currentTimeMillis()) {
                        player.LearnSkill.Time = -1;
                        try {
                            var curSkill = SkillUtil.createSkill(SkillUtil.getTempSkillSkillByItemID(player.LearnSkill.ItemTemplateSkillId),
                                    SkillUtil.getSkillByItemID(player, player.LearnSkill.ItemTemplateSkillId).point);
                            player.BoughtSkill.add((int) player.LearnSkill.ItemTemplateSkillId);
                            SkillUtil.setSkill(player, curSkill);
                            var msg = Service.gI().messageSubCommand((byte) 62);
                            msg.writer().writeShort(curSkill.skillId);
                            player.sendMessage(msg);
                            msg.cleanup();
                            PlayerService.gI().sendInfoHpMpMoney(player);
                        } catch (Exception e) {
                            Logger.log(e.toString());
                        }
                    }
                    ArrayList<String> menu = new ArrayList<>();
                    menu.add("Nhiệm vụ");
                    menu.add("Học\nKỹ năng");
                    if (player.clan != null) {
                        menu.add("Về khu\nvực bang");
                        if (player.clan.isLeader(player)) {
                            menu.add("Giải tán\nBang hội");
                            menu.add("Kho báu\ndưới biển");
                        }
                    }
                    this.createOtherMenu(player, 0, "Chào con, ta rất vui khi gặp con\nCon muốn làm gì nào ?", menu.toArray(new String[0]));
                } else if (select == 2) {
                    Item ruacon = InventoryService.gI().findItemBag(player, 874);
                    if (ruacon != null && ruacon.quantity >= 1) {
                        this.createOtherMenu(player, 1, "Cảm ơn cậu đã cứu con rùa của ta\nĐể cảm ơn ta sẽ tặng cậu món quà.", "Nhận quà", "Đóng");
                    }
                }
                break;

            case 13:
                break;

            case 12:
                if (select == 1) {
                    this.createOtherMenu(player, 13, "Con có muốn huỷ học kỹ năng này và nhận lại 50% số tiềm năng không ?", "Ok", "Đóng");
                } else if (select == 0) {
                    long time = player.LearnSkill.Time - System.currentTimeMillis();
                    int ngoc = 5;
                    if (time / 600_000 >= 2) {
                        ngoc += time / 600_000;
                    }
                    if (player.inventory.gem < ngoc) {
                        Service.gI().sendThongBao(player, "Bạn không có đủ ngọc");
                        return;
                    }
                    player.inventory.subGem(ngoc);
                    player.LearnSkill.Time = -1;
                    try {
                        String[] subName = ItemService.gI().getTemplate(player.LearnSkill.ItemTemplateSkillId).name.split("");
                        byte level = Byte.parseByte(subName[subName.length - 1]);
                        Skill curSkill = SkillUtil.getSkillByItemID(player, player.LearnSkill.ItemTemplateSkillId);
                        if (curSkill.point == 0) {
                            player.BoughtSkill.add((int) player.LearnSkill.ItemTemplateSkillId);
                            curSkill = SkillUtil.createSkill(SkillUtil.getTempSkillSkillByItemID(player.LearnSkill.ItemTemplateSkillId), level);
                        }
                        SkillUtil.setSkill(player, curSkill);
                        var msg = Service.gI().messageSubCommand((byte) 62);
                        msg.writer().writeShort(curSkill.skillId);
                        player.sendMessage(msg);
                        msg.cleanup();
                        PlayerService.gI().sendInfoHpMpMoney(player);
                    } catch (Exception e) {
                        Logger.log(e.toString());
                    }
                }
                break;

            case 0:
                switch (select) {
                    case 0:
                        NpcService.gI().createTutorial(player, tempId, avartar, player.playerTask.taskMain.subTasks.get(player.playerTask.taskMain.index).name);
                        break;
                    case 1:
                        if (player.LearnSkill.Time != -1) {
                            int ngoc = 5;
                            long time = player.LearnSkill.Time - System.currentTimeMillis();
                            if (time / 600_000 >= 2) {
                                ngoc += time / 600_000;
                            }
                            String[] subName = ItemService.gI().getTemplate(player.LearnSkill.ItemTemplateSkillId).name.split("");
                            byte level = Byte.parseByte(subName[subName.length - 1]);
                            this.createOtherMenu(player, 12, "Con đang học kỹ năng\n" + SkillUtil.findSkillTemplate(SkillUtil.getTempSkillSkillByItemID(player.LearnSkill.ItemTemplateSkillId)).name + " cấp " + level + "\nThời gian còn lại " + TimeUtil.getTime(time), "Học\nCấp tốc\n" + ngoc + " ngọc", "Huỷ", "Bỏ qua");
                        } else {
                            ShopService.gI().opendShop(player, "QUY_LAO", false);
                        }
                        break;
                    case 2:
                        if (player.clan != null) {
                            ChangeMapService.gI().changeMapNonSpaceship(player, 153, Util.nextInt(100, 200), 432);
                        }
                        break;
                    case 3:
                        if (player.clan != null && player.clan.isLeader(player)) {
                            createOtherMenu(player, 4, "Con có chắc muốn giải tán bang hội không?", "Đồng ý", "Từ chối");
                        }
                        break;
                    case 4:
                        if (player.clan != null && player.clan.BanDoKhoBau != null) {
                            this.createOtherMenu(player, ConstNpc.MENU_OPENED_DBKB, "Bang hội con đang ở hang kho báu cấp " + player.clan.BanDoKhoBau.level + "\ncon có muốn đi cùng họ không?", "Top\nBang hội", "Thành tích\nBang", "Đồng ý", "Từ chối");
                        } else {
                            this.createOtherMenu(player, ConstNpc.MENU_OPEN_DBKB, "Đây là bản đồ kho báu hải tặc tí hon\nCác con cứ yên tâm lên đường\nỞ đây có ta lo\nNhớ chọn cấp độ vừa sức mình nhé", "Top\nBang hội", "Thành tích\nBang", "Chọn\ncấp độ", "Từ chối");
                        }
                        break;
                }
                break;

            case 4:
                if (player.clan != null && player.clan.isLeader(player) && select == 0) {
                    Input.gI().createFormGiaiTanBangHoi(player);
                }
                break;

            case ConstNpc.MENU_OPENED_DBKB:
                if (select == 2) {
                    if (player.clan == null) {
                        Service.gI().sendThongBao(player, "Hãy vào bang hội trước");
                    } else if (player.isAdmin() || player.nPoint.power >= TreasureUnderSea.POWER_CAN_GO_TO_DBKB) {
                        ChangeMapService.gI().goToDBKB(player);
                    } else {
                        this.npcChat(player, "Yêu cầu sức mạnh lớn hơn " + Util.numberToMoney(TreasureUnderSea.POWER_CAN_GO_TO_DBKB));
                    }
                }
                break;

            case ConstNpc.MENU_OPEN_DBKB:
                if (select == 2) {
                    if (player.clan == null) {
                        Service.gI().sendThongBao(player, "Hãy vào bang hội trước");
                    } else if (player.isAdmin() || player.nPoint.power >= TreasureUnderSea.POWER_CAN_GO_TO_DBKB) {
                        Input.gI().createFormChooseLevelBDKB(player);
                    } else {
                        this.npcChat(player, "Yêu cầu sức mạnh lớn hơn " + Util.numberToMoney(TreasureUnderSea.POWER_CAN_GO_TO_DBKB));
                    }
                }
                break;

            case ConstNpc.MENU_ACCEPT_GO_TO_BDKB:
                if (select == 0) {
                    TreasureUnderSeaService.gI().openBanDoKhoBau(player, Byte.parseByte(String.valueOf(PLAYERID_OBJECT.get(player.id))));
                }
                break;
        }
    }
}
