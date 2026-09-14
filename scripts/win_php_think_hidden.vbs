' Run ThinkPHP console command with no console window (for Windows Task Scheduler).
' Usage:
'   wscript.exe //B //Nologo win_php_think_hidden.vbs "C:\path\php.exe" "C:\path\think" fanshub:uid-sugar
If WScript.Arguments.Count < 3 Then
  WScript.Quit 1
End If

Dim php, thinkPath, cmdName, sh, workDir, slash, line
php = WScript.Arguments(0)
thinkPath = WScript.Arguments(1)
cmdName = WScript.Arguments(2)

slash = InStrRev(thinkPath, "\")
If slash > 1 Then
  workDir = Left(thinkPath, slash - 1)
Else
  workDir = "."
End If

Set sh = CreateObject("WScript.Shell")
sh.CurrentDirectory = workDir
' 0 = hidden window, False = do not wait
line = """" & php & """ """ & thinkPath & """ " & cmdName
sh.Run line, 0, False
