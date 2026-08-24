ini = "C:/Users/dipso/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe/php.ini"
ext = "C:\\Users\\dipso\\AppData\\Local\\Microsoft\\WinGet\\Packages\\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\\ext"
with open(ini, 'r') as f:
    lines = f.readlines()
new_lines = []
for line in lines:
    if line.startswith('extension_dir'):
        new_lines.append('extension_dir = "' + ext + '"\n')
    else:
        new_lines.append(line)
with open(ini, 'w') as f:
    f.writelines(new_lines)
print('Done')
