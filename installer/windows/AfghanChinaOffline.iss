; Afghan China - Offline Mode installer.
; Produces: Afghan-China-Offline-Setup.exe
;
;   ISCC.exe /DAppVersion=1.0.0 AfghanChinaOffline.iss
;
; The payload/ directory next to this file is built first:
;   powershell -ExecutionPolicy Bypass -File build-payload.ps1

#ifndef AppVersion
  #define AppVersion "1.0.0"
#endif

[Setup]
AppId={{7C4E9A2B-1F3D-4E5A-8C6B-9D0E1F2A3B4C}
AppName=Afghan China Offline
AppVersion={#AppVersion}
AppPublisher=Afghan China Shopping Center
DefaultDirName={autopf}\Afghan China Offline
DefaultGroupName=Afghan China
OutputDir=dist
OutputBaseFilename=Afghan-China-Offline-Setup
Compression=lzma2/ultra64
SolidCompression=yes
ArchitecturesAllowed=x64compatible
PrivilegesRequired=admin
WizardStyle=modern
DisableProgramGroupPage=yes
UninstallDisplayName=Afghan China Offline

[Dirs]
; Writable state lives outside Program Files (normal users cannot write there).
Name: "{commonappdata}\AfghanChina\data"; Permissions: users-modify
Name: "{commonappdata}\AfghanChina\data\backups"; Permissions: users-modify
; The app's own scratch space (logs, uploads, cache) stays writable too.
Name: "{app}\backend\storage"; Permissions: users-modify
Name: "{app}\backend\bootstrap\cache"; Permissions: users-modify

[Files]
Source: "payload\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Tasks]
Name: "desktopicon"; Description: "Desktop icon"; GroupDescription: "Shortcuts:"
Name: "startup"; Description: "Start automatically with Windows"; GroupDescription: "Shortcuts:"

[Icons]
Name: "{group}\Afghan China"; Filename: "{app}\AfghanChina.cmd"; WorkingDir: "{app}"
Name: "{group}\Read Me First"; Filename: "{app}\README-FIRST.txt"
Name: "{group}\Stop Afghan China"; Filename: "{app}\Stop-AfghanChina.cmd"; WorkingDir: "{app}"
Name: "{userdesktop}\Afghan China"; Filename: "{app}\AfghanChina.cmd"; WorkingDir: "{app}"; Tasks: desktopicon
Name: "{userstartup}\Afghan China"; Filename: "{app}\AfghanChina.cmd"; WorkingDir: "{app}"; Tasks: startup

[Run]
Filename: "{app}\AfghanChina.cmd"; Description: "Start Afghan China now"; Flags: postinstall nowait skipifsilent

[Code]
var
  CentralPage: TInputQueryWizardPage;

procedure InitializeWizard();
begin
  CentralPage := CreateInputQueryPage(wpSelectDir,
    'Central server', 'Where does this PC send its work?',
    'The address of the online Afghan China server (https). ' +
    'Leave it empty to fill it in later in backend\.env as CENTRAL_URL. ' +
    'The till sells offline either way.');
  CentralPage.Add('Central URL (https://...):', False);
  CentralPage.Values[0] := '';
end;

function Artisan(Params: String): Boolean;
var
  ResultCode: Integer;
begin
  Result := Exec(ExpandConstant('{app}\php\php.exe'), 'artisan ' + Params,
    ExpandConstant('{app}\backend'), SW_HIDE, ewWaitUntilTerminated, ResultCode)
    and (ResultCode = 0);
  if not Result then
    Log('artisan ' + Params + ' failed, code ' + IntToStr(ResultCode));
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  DataDir, DbFile, EnvFile, Template, Env, Central: String;
  ResultCode: Integer;
begin
  DataDir := ExpandConstant('{commonappdata}\AfghanChina\data');
  DbFile := DataDir + '\offline.sqlite';

  if CurStep = ssInstall then
  begin
    { Upgrades: snapshot the live database BEFORE any file is replaced. }
    if FileExists(DbFile) and FileExists(ExpandConstant('{app}\php\php.exe'))
       and FileExists(ExpandConstant('{app}\backend\artisan')) then
    begin
      Log('Existing database found - taking a pre-upgrade backup');
      Exec(ExpandConstant('{app}\php\php.exe'),
        'artisan offline:backup --label=pre-upgrade',
        ExpandConstant('{app}\backend'), SW_HIDE, ewWaitUntilTerminated, ResultCode);
    end;
  end;

  if CurStep = ssPostInstall then
  begin
    EnvFile := ExpandConstant('{app}\backend\.env');

    { Never overwrite an existing .env on upgrade: it holds this PC's keys,
      device identity and central URL. }
    if not FileExists(EnvFile) then
    begin
      Central := Trim(CentralPage.Values[0]);
      LoadStringFromFile(ExpandConstant('{app}\env.template'), Template);
      Env := Template;
      StringChangeEx(Env, '__PORT__', '8080', True);
      StringChangeEx(Env, '__DATA_DIR__', DataDir, True);
      StringChangeEx(Env, '__CENTRAL_URL__', Central, True);
      StringChangeEx(Env, '__APP_VERSION__', '{#AppVersion}', True);
      SaveStringToFile(EnvFile, Env, False);
      Artisan('key:generate --force');
    end;

    { Safe to re-run on every install and upgrade. }
    Artisan('migrate --force');
    Artisan('config:clear');

    { Point the bundled dashboard at this PC's own server. }
    SaveStringToFile(ExpandConstant('{app}\backend\public\app\config.js'),
      'window.__API_URL__ = ''http://127.0.0.1:8080'';' + #13#10, False);
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  { Silent uninstalls (scripts, upgrades) always keep user data; only an
    interactive uninstall asks, and the default answer is NO. }
  if (CurUninstallStep = usUninstall) and (not UninstallSilent()) then
  begin
    if MsgBox('Delete this PC''s local sales data too?' + #13#10 +
       ExpandConstant('{commonappdata}\AfghanChina') + #13#10 + #13#10 +
       'Choose NO to keep it for a future reinstall.',
       mbConfirmation, MB_YESNO) = IDYES then
      DelTree(ExpandConstant('{commonappdata}\AfghanChina'), True, True, True)
    else
      Log('Keeping user data at ' + ExpandConstant('{commonappdata}\AfghanChina'));
  end;
end;
