Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
folder = fso.GetParentFolderName(WScript.ScriptFullName)
shell.CurrentDirectory = folder

jar = folder & "\dist\NgocRongOnline.jar"
If Not fso.FileExists(jar) Then
    MsgBox "Chua co dist\NgocRongOnline.jar" & vbCrLf & "Hay build project trong NetBeans truoc.", vbCritical, "NRO Server"
    WScript.Quit 1
End If

cmd = """" & folder & "\run.bat"""
shell.Run cmd, 0, False
