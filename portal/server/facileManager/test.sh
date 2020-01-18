#! /bin/bash
pci_addr=`ethtool -i ${1} | grep bus-info | awk '{print $2;}'`
echo $pci_addr
