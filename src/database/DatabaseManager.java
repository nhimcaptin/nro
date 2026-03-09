package database;

import java.sql.ResultSet;
import java.sql.PreparedStatement;
import java.io.FileInputStream;
import java.util.Properties;
import java.sql.SQLException;
import java.sql.Connection;
import com.zaxxer.hikari.HikariDataSource;
import com.zaxxer.hikari.HikariConfig;
import data.ResultSetImpl;
import data.ResultSetImpl;
import java.io.IOException;
import utils.Logger;
import database.DatabaseResultSet;

public class DatabaseManager {

    private static String DRIVER;
    private static String URL;
    private static String DB_HOST;
    private static String DB_PORT;
    public static String DB_NAME;
    private static String DB_USER;
    private static String DB_PASSWORD;
    private static int MIN_CONN;
    private static int MAX_CONN;
    private static long MAX_LIFE_TIME;
    public static boolean LOG_QUERY;
    private static HikariConfig config;
    private static HikariDataSource ds;

    static {
        loadProperties();
        config = createConfig("User NgocRongBlackGoku", DB_NAME);
        ds = new HikariDataSource(config);
    }

    public static Connection getConnection() throws SQLException {
        return DatabaseManager.ds.getConnection();
    }

    public static void close() {
        DatabaseManager.ds.close();
    }

    private static void loadProperties() {
        Properties properties = new Properties();
        try {
            properties.load(new FileInputStream("Config.properties"));
            Object value;
            if ((value = properties.get("database.driver")) != null) {
                DatabaseManager.DRIVER = String.valueOf(value);
            }
            if ((value = properties.get("database.host")) != null) {
                DatabaseManager.DB_HOST = String.valueOf(value);
            }
            if ((value = properties.get("database.port")) != null) {
                DatabaseManager.DB_PORT = String.valueOf(value);
            }
            if ((value = properties.get("database.name")) != null) {
                DatabaseManager.DB_NAME = String.valueOf(value);
            }
            if ((value = properties.get("database.user")) != null) {
                DatabaseManager.DB_USER = String.valueOf(value);
            }
            if ((value = properties.get("database.pass")) != null) {
                DatabaseManager.DB_PASSWORD = String.valueOf(value);
            }
            if ((value = properties.get("database.min")) != null) {
                DatabaseManager.MIN_CONN = Integer.parseInt(String.valueOf(value));
            }
            if ((value = properties.get("database.max")) != null) {
                DatabaseManager.MAX_CONN = Integer.parseInt(String.valueOf(value));
            }
            if ((value = properties.get("database.lifetime")) != null) {
                DatabaseManager.MAX_LIFE_TIME = Integer.parseInt(String.valueOf(value));
            }
            if ((value = properties.get("database.log")) != null) {
                DatabaseManager.LOG_QUERY = Boolean.parseBoolean(String.valueOf(value));
            }
               Logger.success("\u001B[31m███╗   ███╗███████╗██╗    ██╗ ██████╗ \n" +
               "\u001B[32m████╗ ████║██╔════╝██║    ██║██╔═══██╗\n" +
               "\u001B[33m██╔████╔██║█████╗  ██║ █╗ ██║██║   ██║\n" +
               "\u001B[34m██║╚██╔╝██║██╔══╝  ██║███╗██║██║   ██║\n" +
               "\u001B[35m██║ ╚═╝ ██║███████╗╚███╔███╔╝╚██████╔╝\n" +
               "\u001B[36m╚═╝     ╚═╝╚══════╝ ╚══╝╚══╝  ╚═════╝ \n" +
               "\u001B[0m"); // Reset màu
        } catch (final IOException | NumberFormatException ex) {
            Logger.log(Logger.RED, "Không thể load file properties!\n");
        } finally {
            properties.clear();
        }
    }

    public static DatabaseResultSet executeQuery(final String query) throws Exception {
        try {
            Connection con = getConnection();
            try (PreparedStatement ps = con.prepareStatement(query)) {
                try (ResultSet rs = ps.executeQuery()) {
                    if (DatabaseManager.LOG_QUERY) {
                        Logger.log(Logger.GREEN, "Thực thi thành công câu lệnh: " + ps.toString() + "\n");
                    }
                    return new ResultSetImpl(rs);
                }
            } finally {
                if (con != null) {
                    con.close();
                }
            }
        } catch (Exception ex) {
            Logger.log(Logger.RED, "Có lỗi xảy ra khi thực thi câu lệnh: " + query + "\n");
            throw ex;
        }
    }

    public static DatabaseResultSet executeQuery(final String query, final Object... objs) throws Exception {
        try (final Connection con = getConnection(); final PreparedStatement ps = con.prepareStatement(query)) {
            for (int i = 0; i < objs.length; ++i) {
                ps.setObject(i + 1, objs[i]);
            }
            if (DatabaseManager.LOG_QUERY) {
                Logger.log(Logger.GREEN, "Thực thi thành công câu lệnh: " + ps.toString() + "\n");
            }
            return new ResultSetImpl(ps.executeQuery());
        } catch (final Exception ex) {
            Logger.log(Logger.RED, "Có lỗi xảy ra khi thực thi câu lệnh: " + query + "\n");
            throw ex;
        }
    }

    public static int executeUpdate(final String query) throws Exception {
        int rowUpdated = -1;
        try (final Connection con = getConnection(); final PreparedStatement ps = con.prepareStatement(query)) {
            if (DatabaseManager.LOG_QUERY) {
                Logger.log(Logger.GREEN, "Thực thi thành công câu lệnh: " + ps.toString() + "\n");
            }
            rowUpdated = ps.executeUpdate();
        } catch (final Exception e) {
            Logger.log(Logger.RED, "Có lỗi xảy ra khi thực thi câu lệnh: " + query + "\n");
            throw e;
        }
        return rowUpdated;
    }

    public static int executeUpdate(String query, final Object... objs) throws Exception {
        if (query.indexOf("insert") == 0 && query.lastIndexOf("()") == query.length() - 2) {
            final StringBuilder sb = new StringBuilder();
            sb.append("(");
            for (int i = 0; i < objs.length; ++i) {
                sb.append("?");
                if (i < objs.length - 1) {
                    sb.append(",");
                } else {
                    sb.append(")");
                }
            }
            query = query.replace("()", sb.toString());
        }
        try (final Connection con = getConnection(); final PreparedStatement ps = con.prepareStatement(query)) {
            for (int j = 0; j < objs.length; ++j) {
                ps.setObject(j + 1, objs[j]);
            }
            if (DatabaseManager.LOG_QUERY) {
                Logger.log(Logger.GREEN, "Thực thi thành công câu lệnh: " + ps.toString() + "\n");
            }
            return ps.executeUpdate();
        } catch (final Exception ex) {
            Logger.log(Logger.RED, "Có lỗi xảy ra khi thực thi câu lệnh: " + query + "\n");
            throw ex;
        }
    }

    private static HikariConfig createConfig(String poolName, String databaseName) {
        HikariConfig config = new HikariConfig();
        config.setDriverClassName(DRIVER);
        config.setJdbcUrl(String.format("jdbc:mysql://%s:%s/%s?useUnicode=yes&characterEncoding=UTF-8",
                DB_HOST, DB_PORT, databaseName));
        config.setUsername(DB_USER);
        config.setPassword(DB_PASSWORD);
        config.setMinimumIdle(MIN_CONN);
        config.setMaximumPoolSize(MAX_CONN);
        config.setMaxLifetime(MAX_LIFE_TIME);
        config.setPoolName(poolName);
        config.addDataSourceProperty("cachePrepStmts", "true");
        config.addDataSourceProperty("prepStmtCacheSize", "250");
        config.addDataSourceProperty("prepStmtCacheSqlLimit", "2048");
        config.addDataSourceProperty("useServerPrepStmts", "true");;
        return config;
    }
}