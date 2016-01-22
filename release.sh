#!/bin/bash
tar -cvzf "messagebird-magento-$1.tgz" --exclude .git --exclude .DS_Store app lib README.md package.xml
